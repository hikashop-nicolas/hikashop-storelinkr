<?php
namespace HikashopStorelinkr\Service;

use HikashopStorelinkr\Service;
use HikashopStorelinkr\ApiError;
use HikashopStorelinkr\Schema;
use HikashopStorelinkr\ProductMapper;

/**
 * Ingests images from a CDN url or base64 content and records them as HikaShop
 * product files. cdn_url fetches are SSRF-guarded: http(s) only, private and
 * loopback hosts blocked, with an optional host allowlist (plugin param). The
 * bytes are written into HikaShop's upload folder; HikaShop generates
 * thumbnails on display, so only the original is stored.
 */

class ImageService extends Service {

	/** Standalone image (images/create): stored unattached, returns id + url. */
	public function createStandalone($alt, $cdnUrl, $imageContent) {
		$bytes = $this->resolveBytes($cdnUrl, $imageContent);
		$filename = $this->store($bytes, $alt ? $alt : 'image');
		$fileId = $this->createFileRecord($filename, 0, $alt);
		return array(
			'image_id' => $fileId,
			'media_id' => $fileId,
			'image_url' => $this->fileUrl($filename),
		);
	}

	/** Attach a downloaded/decoded image to a product, returns the new file id. */
	public function ingestForProduct($productId, $alt, $cdnUrl, $imageContent) {
		$bytes = $this->resolveBytes($cdnUrl, $imageContent);
		$filename = $this->store($bytes, $alt ? $alt : ('product_' . (int)$productId));
		return $this->createFileRecord($filename, (int)$productId, $alt);
	}

	private function resolveBytes($cdnUrl, $imageContent) {
		if(!empty($cdnUrl)) {
			return $this->download($cdnUrl);
		}
		if(!empty($imageContent)) {
			$decoded = base64_decode($imageContent, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- StoreLinkr sends image bytes as base64.
			if($decoded === false || $decoded === '') {
				throw ApiError::because('Empty or invalid image content!');
			}
			return $decoded;
		}
		throw ApiError::because('Please set the image content or provide a CDN url!');
	}

	private function download($url) {
		$this->assertSafeUrl($url);
		list($code, $bytes) = $this->platform->httpGet($url, array('Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8'), 20);
		if($code < 200 || $code >= 300 || $bytes === '') {
			throw ApiError::because('Image download failed for url ' . $url);
		}
		if(strlen($bytes) > 25 * 1024 * 1024) {
			throw ApiError::because('Image exceeds the 25MB limit');
		}
		return $bytes;
	}

	/** Reject non-http(s) schemes, private/loopback hosts, and off-allowlist hosts. */
	private function assertSafeUrl($url) {
		$parts = $this->platform->parseUrl($url);
		if(empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
			throw ApiError::because('Only http(s) image urls are allowed');
		}
		if(empty($parts['host'])) {
			throw ApiError::because('Invalid image url');
		}
		$host = $parts['host'];

		$allowlist = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$this->params->get('image_host_allowlist', ''))));
		if(!empty($allowlist) && !in_array(strtolower($host), array_map('strtolower', $allowlist), true)) {
			throw ApiError::because('Image host not allowed: ' . $host);
		}

		$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
		if($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
			if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				throw ApiError::because('Image host resolves to a private address');
			}
		}
	}

	private function store($bytes, $baseName) {
		$ext = $this->detectExtension($bytes);
		$fileClass = hikashop_get('class.file');
		$path = $fileClass->getPath('product');
		if(empty($path) || !$this->platform->isWritable($path)) {
			throw ApiError::because('Upload folder is not writable');
		}
		$safe = preg_replace('#[^a-z0-9_-]#i', '_', strtolower((string)$baseName));
		$safe = trim(substr($safe, 0, 40), '_');
		if($safe === '') {
			$safe = 'image';
		}
		$filename = 'storelinkr_' . $safe . '_' . strtolower($this->platform->randomString(10)) . '.' . $ext;

		if(!$this->platform->writeFile($path . $filename, $bytes)) {
			throw ApiError::because('Could not write the image file');
		}
		return $filename;
	}

	private function detectExtension($bytes) {
		$head = substr($bytes, 0, 16);
		if(strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
			return 'jpg';
		}
		if(strncmp($head, "\x89PNG", 4) === 0) {
			return 'png';
		}
		if(strncmp($head, 'GIF8', 4) === 0) {
			return 'gif';
		}
		if(strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
			return 'webp';
		}
		throw ApiError::because('Unsupported image format');
	}

	private function createFileRecord($filename, $refId, $alt) {
		$fileClass = hikashop_get('class.file');
		$file = new \stdClass();
		$file->file_path = $filename;
		$file->file_type = 'product';
		$file->file_ref_id = (int) $refId;
		$file->file_name = (string) ($alt !== null ? $alt : $filename);
		$file->file_description = (string) ($alt !== null ? $alt : '');
		$file->file_ordering = 0;
		$id = $fileClass->save($file);
		if(!$id) {
			throw ApiError::because('Could not save the image record');
		}
		return (int) $id;
	}

	private function fileUrl($filename) {
		$config = hikashop_config();
		$folder = trim((string)$config->get('uploadfolder', 'media/com_hikashop/upload/'), '/');
		$base = defined('HIKASHOP_LIVE') ? rtrim(HIKASHOP_LIVE, '/') : '';
		return $base . '/' . $folder . '/' . $filename;
	}
}
