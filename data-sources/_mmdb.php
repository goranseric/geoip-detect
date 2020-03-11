<?php
/*
Copyright 2013-2020 Yellow Tree, Siegen, Germany
Author: Benjamin Pick (wp-geoip-detect| |posteo.de)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
namespace YellowTree\GeoipDetect\DataSources;

use YellowTree\GeoipDetect\DataSources\Manual\ManualDataSource;

require_once(__DIR__ . '/manual.php');

abstract class AbstractMmdbDataSource extends ManualDataSource {
    public function getStatusInformationHTML() {
		$html = parent::getStatusInformationHTML();
		$date_format = get_option('date_format') . ' ' . get_option('time_format');

		$rescheduled = '';
		$next_cron_update = wp_next_scheduled( 'geoipdetectupdate' );
		if ($next_cron_update === false) {
			$rescheduled = ' ' . __('(Was rescheduled just now)', 'geoip-detect');
			$this->set_cron_schedule();
			$next_cron_update = wp_next_scheduled( 'geoipdetectupdate' );
		}
		$html .= '<br />' . sprintf(__('Next update: %s', 'geoip-detect'), $next_cron_update !== false ? date_i18n($date_format, $next_cron_update) : __('Never', 'geoip-detect'));
		$html .= $rescheduled;

		$html .= $this->updateHTML();

		return $html;
    }
    

    protected function updateHTMLvalidation(&$error, &$disabled) { /* template function */ }

    protected function updateHTML() {
		$html = $error = '';
		$disabled = '';

		$this->updateHTMLvalidation($error, $disabled);

		$text_update = __('Update now', 'geoip-detect');
		$nonce_field = wp_nonce_field( 'geoip_detect_update' );
		$id = $this->getId();
		if (current_user_can('manage_options')) {
			$html .= <<<HTML
<form method="post" action="options-general.php?page=geoip-detect%2Fgeoip-detect.php">
		$nonce_field
		<input type="hidden" name="action" value="update" />
		<input type="hidden" name="id" value="$id" />
		<input type="submit" class="button button-secondary" value="$text_update" $disabled />
</form>
HTML;
		}

		if ($error) {
			$error = '<div class="geoip_detect_error" style="margin-top: 10px;">' . $error . '</div>';
		}

		return $html . $error;
	}

    public function __construct() {
		parent::__construct();
		add_action('geoipdetectupdate', array($this, 'hook_cron'), 10, 1);
		add_action('plugins_loaded', array($this, 'on_plugins_loaded'));
	}

	public function on_plugins_loaded() {
		if (!defined('GEOIP_DETECT_AUTO_UPDATE_DEACTIVATED'))
			define('GEOIP_DETECT_AUTO_UPDATE_DEACTIVATED', false);
    }
    
	public function maxmindGetFilename() {
		$data_filename = $this->maxmindGetUploadFilename();
		if (!is_readable($data_filename))
			$data_filename = '';

		$data_filename = apply_filters('geoip_detect_get_abs_db_filename', $data_filename);
		return $data_filename;
	}


	protected function download_url($url, $modified = 0) {
		// Similar to wordpress download_url, but with custom UA and If-Modified-Since - Header
		$url_filename = basename( parse_url( $url, PHP_URL_PATH ) );

		$tmpfname = wp_tempnam( $url_filename );
		if ( ! $tmpfname )
			return new \WP_Error('http_no_file', __('Could not create temporary file.', 'geoip-detect'));

		$headers = array();
		$headers['User-Agent'] = GEOIP_DETECT_USER_AGENT;
		if ($modified) {
			$headers['If-Modified-Since'] = date('r', $modified);
		}

		$response = wp_safe_remote_get( $url, array('timeout' => 300, 'stream' => true, 'filename' => $tmpfname, 'headers' => $headers ) );
		$http_response_code = wp_remote_retrieve_response_code( $response );
		if (304 === $http_response_code) {
			return new \WP_Error( 'http_304', __('It has not changed since the last update.', 'geoip-detect') );
		}
		if (is_wp_error( $response ) || 200 !=  $http_response_code) {
			unlink($tmpfname);
			$body = wp_remote_retrieve_body($response);
			return new \WP_Error( 'http_404', $http_response_code . ': ' . trim( wp_remote_retrieve_response_message( $response ) ) . ' ' . $body );
		}

		return $tmpfname;
	}

	protected abstract function getDownloadUrl();

	protected function updateTreatError($error) {
		return $error->get_error_message();
	}

	public function maxmindUpdate()
	{
		$file_option_name = 'geoip-detect-' . $this->getId() . '_downloaded_file';
		require_once(ABSPATH.'/wp-admin/includes/file.php');

		$download_url = $this->getDownloadUrl();

		$outFile = $this->maxmindGetUploadFilename();
		$modified = 0;
		if (\is_readable($outFile)) {
			$modified = filemtime($outFile);
		} 

		// Check if existing download should be resumed
		$tmpFile = get_option($file_option_name);
		if (!$tmpFile || (is_string($tmpFile) && !file_exists($tmpFile)) ) {
			// Download file
			$tmpFile = $this->download_url($download_url, $modified);
		} 

		if (is_wp_error($tmpFile)) {
			update_option($file_option_name, '');
			return $this->updateTreatError($tmpFile);
		}
		update_option($file_option_name, $tmpFile);

		// Unpack tar.gz
		$ret = $this->unpackArchive($tmpFile, $outFile);
		if (is_string($ret)) {
			return $ret;
		}

		if (!is_readable($outFile)) {
			return 'Something went wrong: the unpacked file cannot be found.';
		}

		update_option($file_option_name, '');
		unlink($tmpFile);

		return true;
	}

	// Ungzip File
	protected function unpackArchive($downloadedFilename, $outFile) {
		if (!is_readable($downloadedFilename))
			return __('Downloaded file could not be opened for reading.', 'geoip-detect');
		if (!\is_writable(dirname($outFile)))
			return sprintf(__('Database could not be written (%s).', 'geoip-detect'), $outFile);

		$phar = new \PharData( $downloadedFilename );

		$outDir = get_temp_dir() . 'geoip-detect/';

		global $wp_filesystem;
		if (!$wp_filesystem) {
			\WP_Filesystem(false, get_temp_dir());
		}
		if (\is_dir($outDir)) {
			$wp_filesystem->rmdir($outDir, true);
		}

		mkdir($outDir);
		$phar->extractTo($outDir, null, true);

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outDir));

		$inFile = '';
		foreach($files as $file) {
			if (!$file->isDir() && mb_substr($file->getFilename(), -5) == '.mmdb') {
				$inFile = $file->getPathname();
				break;
			}
		}

		if (!\is_readable($inFile))
			return __('Downloaded file could not be opened for reading.', 'geoip-detect');
	
		$ret = copy($inFile, $outFile);
		if (!$ret)
			return sprintf(__('Downloaded file could not write or overwrite %s.', 'geoip-detect'), $outFile);

		$wp_filesystem->rmdir($outDir, true);

		return true;
	}

	public function hook_cron() {
		/**
		 * Filter:
		 * Cron has fired.
		 * Find out if file should be updated now.
		 *
		 * @param $do_it False if deactivated by define
		 * @param $immediately_after_activation True if this is fired because the plugin was recently activated (deprecated, will now always be false)
		 */
		$do_it = apply_filters('geoip_detect_cron_do_update', !GEOIP_DETECT_AUTO_UPDATE_DEACTIVATED, false);

		$this->schedule_next_cron_run();

		if ($do_it)
			$this->maxmindUpdate();
	}

	public function set_cron_schedule()
	{
		$next = wp_next_scheduled( 'geoipdetectupdate' );
		if ( $next === false ) {
			$this->schedule_next_cron_run();
		}
	}

	public function schedule_next_cron_run() {
		// Try to update every 1-2 weeks
		$next = time() + WEEK_IN_SECONDS;
		$next += mt_rand(1, WEEK_IN_SECONDS);
		wp_schedule_single_event($next, 'geoipdetectupdate');
	}

	public function activate() {
		$this->set_cron_schedule();
	}

	public function deactivate()
	{
		wp_clear_scheduled_hook('geoipdetectupdate');
	}

	public function uninstall() {
		// Delete the automatically downloaded file, if it exists
		$filename = $this->maxmindGetFilename();
		if ($filename) {
			unlink($filename);
		}
	}

}