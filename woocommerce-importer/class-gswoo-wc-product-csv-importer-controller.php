<?php
/**
 * Class GSWOO_WC_Product_CSV_Importer_Controller file.
 *
 * @since 1.0.0
 *
 * @package GSWOO
 * @subpackage GSWOO/woocommerce-importer
 */

use Google\Spreadsheet\Exception\SpreadsheetNotFoundException;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Importer' ) ) {
	return;
}

/**
 * Product importer controller - handles file upload and forms in admin.
 *
 * @since 1.0.0
 */
class GSWOO_WC_Product_CSV_Importer_Controller extends WC_Product_CSV_Importer_Controller {
	/**
	 * Output information about the uploading process.
	 *
	 * @since 1.0.0
	 * @noinspection PhpUnusedLocalVariableInspection
	 */
	protected function upload_form() {
		$bytes      = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$size       = size_format( $bytes );
		$upload_dir = wp_upload_dir();

		$options            = get_option( 'plugin_wc_import_google_sheet_options' );
		$google_sheet_title = html_entity_decode( htmlentities( $options['google_sheet_title'] ) );

		// Include plugin custom import form.
		include dirname( __FILE__ ) . '/views/html-product-csv-import-form.php';
	}

	/**
	 * Handles the CSV upload and initial parsing of the file to prepare for
	 * displaying author import options.
	 *
	 * @since 1.0.0
	 *
	 * @noinspection PhpUnusedLocalVariableInspection
	 *
	 * @throws SpreadsheetNotFoundException
	 *
	 * @return string|WP_Error
	 */
	public function handle_upload() {
		// phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification -- Nonce already verified in WC_Product_CSV_Importer_Controller::upload_form_handler()
		$file_url = isset( $_POST['file_url'] ) ? wc_clean( wp_unslash( $_POST['file_url'] ) ) : '';

		if ( empty( $file_url ) ) {
			if ( ! isset( $_REQUEST['file'] ) ) {
				return new WP_Error(
					'woocommerce_product_csv_importer_upload_file_empty',
					__(
						'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.',
						'import-products-from-gsheet-for-woo-importer'
					)
				);
			}

			if ( $_REQUEST['file'] ) {
				$options            = get_option( 'plugin_wc_import_google_sheet_options' );
				$google_sheet_title = wp_specialchars_decode( $options['google_sheet_title'] );

				if ( $google_sheet_title == $_REQUEST['file'] ) {
					$file_content = $this->google_sheet_get_csv_file( $google_sheet_title );

					$upload_dir_arr = wp_upload_dir();

					$file_name       = sanitize_file_name( $_REQUEST['file'] );
					$file_sheet_url  = $upload_dir_arr['url'] . '/' . $file_name . '.csv';
					$file_sheet_path = $upload_dir_arr['path'] . '/' . $file_name . '.csv';

					file_put_contents( $file_sheet_path, $file_content );
				} else {
					return new WP_Error(
						'woocommerce_product_csv_importer_upload_file_invalid',
						__(
							"Your current chosen google sheet title don't set in plugin google sheet title option, please update plugin options and return to import again..",
							'import-products-from-gsheet-for-woo-importer'
						)
					);
				}
			} else {
				return new WP_Error(
					'woocommerce_product_csv_importer_upload_file_invalid',
					__(
						"You don't set google style sheet title setting, please set it and return again",
						'import-products-from-gsheet-for-woo-importer'
					)
				);
			}

			if ( ! self::is_file_valid_csv( wc_clean( wp_unslash( array_slice( explode( '/', $file_sheet_url ), -1 )[0] ) ), false ) ) {
				return new WP_Error(
					'woocommerce_product_csv_importer_upload_file_invalid',
					__(
						'Invalid file type. The importer supports CSV and TXT file formats.',
						'import-products-from-gsheet-for-woo-importer'
					)
				);
			}

			$overrides = array(
				'test_form' => false,
				'mimes'     => self::get_valid_csv_filetypes(),
			);

			$import = $file_sheet_path; // WPCS: sanitization ok, input var ok.

			$upload = array(
				'file' => $file_sheet_path,
				'url'  => $file_sheet_url,
				'type' => 'text/csv',
			);

			// Construct the object array.
			$object = array(
				'post_title'     => basename( $upload['file'] ),
				'post_content'   => $upload['url'],
				'post_mime_type' => $upload['type'],
				'guid'           => $upload['url'],
				'context'        => 'import',
				'post_status'    => 'private',
			);

			// Save the data.
			$id = wp_insert_attachment( $object, $upload['file'] );

			/*
			 * Schedule a cleanup for one day from now in case of failed
			 * import or missing wp_import_cleanup() call.
			 */
			wp_schedule_single_event( time() + DAY_IN_SECONDS, 'importer_scheduled_cleanup', array( $id ) );

			return $upload['file'];
		} elseif ( file_exists( ABSPATH . $file_url ) ) {
			if ( ! self::is_file_valid_csv( ABSPATH . $file_url ) ) {
				return new WP_Error( 'woocommerce_product_csv_importer_upload_file_invalid', __( 'Invalid file type. The importer supports CSV and TXT file formats.', 'import-products-from-gsheet-for-woo-importer' ) );
			}

			return ABSPATH . $file_url;
		}
		// phpcs:enable

		return new WP_Error( 'woocommerce_product_csv_importer_upload_invalid_file', __( 'Please upload or provide the link to a valid CSV file.', 'import-products-from-gsheet-for-woo-importer' ) );
	}

	/**
	 * Gateway for a google sheet api
	 *
	 * @param string $file_name Google sheet file name.
	 *
	 * @return string
	 * @throws SpreadsheetNotFoundException
	 * @since 1.0.0
	 *
	 */
	public function google_sheet_get_csv_file( $file_name ) {
		$google_api_obj = new GSWOO_Wrapper_Api_Google_Drive();
		$google_api_obj->set_sheet( $file_name );

		return $google_api_obj->get_sheet_csv();
	}
}
