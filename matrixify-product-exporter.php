<?php
/**
 * Plugin Name: Matrixify Product Exporter
 * Description: Exporta los productos de WooCommerce a un fichero .xlsx con la misma estructura de columnas que usa Matrixify para importar en Shopify.
 * Version: 1.0.1
 * Author: Adrià
 * Text Domain: matrixify-product-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MPE_VERSION', '1.0.1' );
define( 'MPE_EXPORT_BATCH_SIZE', apply_filters( 'mpe_export_batch_size', 100 ) );

/**
 * ------------------------------------------------------------------
 * Admin menu
 * ------------------------------------------------------------------
 */
add_action( 'admin_menu', function () {
	add_submenu_page(
		'woocommerce',
		'Exportar a Matrixify',
		'Exportar a Matrixify',
		'manage_woocommerce',
		'mpe-export',
		'mpe_render_admin_page'
	);
} );

function mpe_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>Exportar productos a Matrixify</h1>
		<p>Genera un fichero .xlsx con las columnas que espera Matrixify para importar productos en Shopify (una fila por variante/imagen, con Handle, Options, Variant SKU, precios, stock, imágenes, SEO, etc.).</p>

		<?php if ( isset( $_GET['mpe_error'] ) ) : ?>
			<div class="notice notice-error"><p><strong>Error al exportar:</strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['mpe_error'] ) ) ); ?></p></div>
		<?php endif; ?>

		<p>
			<strong>Estado del entorno:</strong>
			WooCommerce activo: <?php echo class_exists( 'WooCommerce' ) ? '✅' : '❌'; ?> —
			Extensión ZipArchive (para .xlsx): <?php echo class_exists( 'ZipArchive' ) ? '✅' : '❌ (se exportará en .csv en su lugar)'; ?>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'mpe_export_action', 'mpe_export_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">Estado de producto</th>
					<td>
						<label><input type="checkbox" name="mpe_status[]" value="publish" checked> Publicados</label><br>
						<label><input type="checkbox" name="mpe_status[]" value="draft"> Borradores</label><br>
						<label><input type="checkbox" name="mpe_status[]" value="private"> Privados</label>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" name="mpe_do_export" value="1" class="button button-primary">Generar y descargar .xlsx</button>
			</p>
		</form>

		<p><em>Nota:</em> "Body HTML" se rellena con la descripción corta del producto. La descripción larga no se usa porque en esta tienda está generada por el constructor de BeTheme y no contiene HTML utilizable. Algunos campos tampoco tienen un equivalente directo en WooCommerce estándar (Vendor/marca, Barcode, Cost); el plugin los rellena solo si detecta los campos habituales (taxonomía <code>product_brand</code>, meta <code>_global_unique_id</code>/<code>_barcode</code>, meta de "Cost of Goods"). Revisa el fichero generado antes de importarlo en Shopify.</p>
	</div>
	<?php
}

/**
 * ------------------------------------------------------------------
 * Handle export request (fires before headers are sent)
 * ------------------------------------------------------------------
 */
add_action( 'admin_init', function () {
	if ( ! isset( $_POST['mpe_do_export'] ) ) {
		return;
	}
	if ( ! isset( $_POST['mpe_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mpe_export_nonce'] ) ), 'mpe_export_action' ) ) {
		wp_die( 'Nonce inválido.' );
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'No autorizado.' );
	}

	$statuses = isset( $_POST['mpe_status'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mpe_status'] ) ) : array( 'publish' );
	if ( empty( $statuses ) ) {
		$statuses = array( 'publish' );
	}

	try {
		mpe_stream_export( $statuses );
		exit;
	} catch ( \Throwable $e ) {
		// Catch fatals too (e.g. missing ZipArchive), not just Exceptions.
		if ( function_exists( 'error_log' ) ) {
			error_log( 'Matrixify Product Exporter: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		}
		$redirect = add_query_arg( 'mpe_error', rawurlencode( $e->getMessage() ), remove_query_arg( array( 'mpe_do_export' ) ) );
		wp_safe_redirect( $redirect );
		exit;
	}
} );

/**
 * ------------------------------------------------------------------
 * Column definition (exact order/names required by Matrixify)
 * ------------------------------------------------------------------
 */
function mpe_columns() {
	return array(
		'Handle', 'Command', 'Title', 'Body HTML', 'Vendor', 'Type', 'Tags', 'Tags Command',
		'Status', 'Published', 'Published At', 'Published Scope', 'Template Suffix', 'Gift Card',
		'Category: ID', 'Category: Name', 'Category', 'Custom Collections',
		'Image Attachment', 'Image Src', 'Image Command', 'Image Position', 'Image Alt Text',
		'Variant ID', 'Variant Command', 'Option1 Name', 'Option1 Value', 'Option2 Name', 'Option2 Value',
		'Option3 Name', 'Option3 Value', 'Variant Generate From Options', 'Variant Position',
		'Variant SKU', 'Variant Barcode', 'Variant Image', 'Variant Weight', 'Variant Weight Unit',
		'Variant Price', 'Variant Compare At Price', 'Variant Cost', 'Variant Taxable',
		'Variant Inventory Tracker', 'Variant Inventory Policy', 'Variant Fulfillment Service',
		'Variant Requires Shipping', 'Variant Shipping Profile', 'Variant Inventory Qty',
		'Variant Inventory Adjust', 'Variant HS Code', 'Variant Country of Origin', 'Variant Province of Origin',
		'Metafield: title_tag [string]', 'Metafield: description_tag [string]',
	);
}

/**
 * ------------------------------------------------------------------
 * Build rows for one product and stream them into the writer
 * ------------------------------------------------------------------
 */
function mpe_build_product_rows( WC_Product $product ) {
	$rows = array();

	$handle       = $product->get_slug();
	$title        = $product->get_name();
	// Solo la descripción corta: la larga viene del constructor de BeTheme
	// (Fusion/Muffin Builder) y no contiene HTML utilizable para Shopify.
	$body_html    = $product->get_short_description();
	$vendor       = mpe_get_vendor( $product );
	$type         = mpe_get_primary_category_name( $product );
	$tags         = mpe_get_tags( $product );
	$status_map   = array( 'publish' => 'active', 'draft' => 'draft', 'pending' => 'draft', 'private' => 'archived' );
	$status       = isset( $status_map[ $product->get_status() ] ) ? $status_map[ $product->get_status() ] : 'draft';
	$published    = ( 'publish' === $product->get_status() && $product->is_visible() ) ? 'TRUE' : 'FALSE';
	$published_at = $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i:s O' ) : '';
	$category_full = mpe_get_category_path( $product );
	$collections   = mpe_get_all_categories( $product );
	$seo_title     = mpe_get_seo_title( $product );
	$seo_desc      = mpe_get_seo_description( $product );

	$images   = mpe_get_images( $product );
	$variants = mpe_get_variants( $product );

	$row_count = max( count( $variants ), count( $images ), 1 );

	for ( $i = 0; $i < $row_count; $i++ ) {
		$is_first = ( 0 === $i );
		$image    = isset( $images[ $i ] ) ? $images[ $i ] : null;
		$variant  = isset( $variants[ $i ] ) ? $variants[ $i ] : null;

		$row = array(
			'Handle'          => $handle,
			'Command'         => 'MERGE',
			'Title'           => $is_first ? $title : '',
			'Body HTML'       => $is_first ? $body_html : '',
			'Vendor'          => $is_first ? $vendor : '',
			'Type'            => $is_first ? $type : '',
			'Tags'            => $is_first ? $tags : '',
			'Tags Command'    => $is_first ? 'MERGE' : '',
			'Status'          => $is_first ? $status : '',
			'Published'       => $is_first ? $published : '',
			'Published At'    => $is_first ? $published_at : '',
			'Published Scope' => $is_first ? 'global' : '',
			'Template Suffix' => '',
			'Gift Card'       => $is_first ? 'FALSE' : '',
			'Category: ID'    => '',
			'Category: Name'  => $is_first ? $type : '',
			'Category'        => $is_first ? $category_full : '',
			'Custom Collections' => $is_first ? $collections : '',
			'Image Attachment' => '',
			'Image Src'        => $image ? $image['src'] : '',
			'Image Command'    => $image ? 'MERGE' : '',
			'Image Position'   => $image ? ( $i + 1 ) : '',
			'Image Alt Text'   => $image ? $image['alt'] : '',
			'Variant ID'       => '',
			'Variant Command'  => $variant ? 'MERGE' : '',
			'Option1 Name'     => $variant ? $variant['option1_name'] : '',
			'Option1 Value'    => $variant ? $variant['option1_value'] : '',
			'Option2 Name'     => $variant ? $variant['option2_name'] : '',
			'Option2 Value'    => $variant ? $variant['option2_value'] : '',
			'Option3 Name'     => $variant ? $variant['option3_name'] : '',
			'Option3 Value'    => $variant ? $variant['option3_value'] : '',
			'Variant Generate From Options' => $variant ? 'FALSE' : '',
			'Variant Position' => $variant ? ( $i + 1 ) : '',
			'Variant SKU'      => $variant ? $variant['sku'] : '',
			'Variant Barcode'  => $variant ? $variant['barcode'] : '',
			'Variant Image'    => $variant ? $variant['image'] : '',
			'Variant Weight'   => $variant ? $variant['weight'] : '',
			'Variant Weight Unit' => $variant ? $variant['weight_unit'] : '',
			'Variant Price'    => $variant ? $variant['price'] : '',
			'Variant Compare At Price' => $variant ? $variant['compare_at_price'] : '',
			'Variant Cost'     => $variant ? $variant['cost'] : '',
			'Variant Taxable'  => $variant ? $variant['taxable'] : '',
			'Variant Inventory Tracker' => $variant ? $variant['inventory_tracker'] : '',
			'Variant Inventory Policy'  => $variant ? $variant['inventory_policy'] : '',
			'Variant Fulfillment Service' => $variant ? 'manual' : '',
			'Variant Requires Shipping' => $variant ? $variant['requires_shipping'] : '',
			'Variant Shipping Profile'  => '',
			'Variant Inventory Qty'     => $variant ? $variant['inventory_qty'] : '',
			'Variant Inventory Adjust'  => $variant ? 0 : '',
			'Variant HS Code'           => '',
			'Variant Country of Origin' => '',
			'Variant Province of Origin' => '',
			'Metafield: title_tag [string]' => $is_first ? $seo_title : '',
			'Metafield: description_tag [string]' => $is_first ? $seo_desc : '',
		);

		$rows[] = array_values( $row );
	}

	return $rows;
}

/**
 * ------------------------------------------------------------------
 * Field helpers
 * ------------------------------------------------------------------
 */
function mpe_get_vendor( WC_Product $product ) {
	if ( taxonomy_exists( 'product_brand' ) ) {
		$terms = get_the_terms( $product->get_id(), 'product_brand' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			return $terms[0]->name;
		}
	}
	return '';
}

function mpe_get_primary_category_name( WC_Product $product ) {
	$terms = get_the_terms( $product->get_id(), 'product_cat' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		return $terms[0]->name;
	}
	return '';
}

function mpe_get_category_path( WC_Product $product ) {
	$terms = get_the_terms( $product->get_id(), 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	$term = $terms[0];
	$path = array( $term->name );
	while ( $term->parent ) {
		$term = get_term( $term->parent, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			break;
		}
		array_unshift( $path, $term->name );
	}
	return implode( ' > ', $path );
}

function mpe_get_all_categories( WC_Product $product ) {
	$terms = get_the_terms( $product->get_id(), 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	return implode( ', ', wp_list_pluck( $terms, 'name' ) );
}

function mpe_get_tags( WC_Product $product ) {
	$terms = get_the_terms( $product->get_id(), 'product_tag' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	return implode( ', ', wp_list_pluck( $terms, 'name' ) );
}

function mpe_get_seo_title( WC_Product $product ) {
	$id = $product->get_id();
	$v  = get_post_meta( $id, '_yoast_wpseo_title', true );
	if ( $v ) {
		return $v;
	}
	return get_post_meta( $id, 'rank_math_title', true );
}

function mpe_get_seo_description( WC_Product $product ) {
	$id = $product->get_id();
	$v  = get_post_meta( $id, '_yoast_wpseo_metadesc', true );
	if ( $v ) {
		return $v;
	}
	return get_post_meta( $id, 'rank_math_description', true );
}

function mpe_get_images( WC_Product $product ) {
	$images    = array();
	$image_ids = array();

	if ( $product->get_image_id() ) {
		$image_ids[] = $product->get_image_id();
	}
	$image_ids = array_merge( $image_ids, $product->get_gallery_image_ids() );
	$image_ids = array_unique( $image_ids );

	foreach ( $image_ids as $attachment_id ) {
		$src = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( ! $src ) {
			continue;
		}
		$images[] = array(
			'src' => $src,
			'alt' => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	return $images;
}

function mpe_get_variants( WC_Product $product ) {
	if ( $product->is_type( 'variable' ) ) {
		return mpe_get_variable_variants( $product );
	}
	return array( mpe_variant_from_product( $product, array(), array() ) );
}

function mpe_get_variable_variants( WC_Product_Variable $product ) {
	$variants         = array();
	$attribute_names  = array_keys( $product->get_variation_attributes() ? $product->get_variation_attributes() : array() );
	$attribute_labels = array();
	foreach ( $attribute_names as $attr_name ) {
		$attribute_labels[ $attr_name ] = wc_attribute_label( $attr_name, $product );
	}

	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			continue;
		}
		$variation_attrs = $variation->get_variation_attributes(); // keys like attribute_pa_color
		$option_names    = array();
		$option_values   = array();
		foreach ( $variation_attrs as $attr_key => $value ) {
			$attr_name = str_replace( 'attribute_', '', $attr_key );
			$option_names[]  = isset( $attribute_labels[ $attr_name ] ) ? $attribute_labels[ $attr_name ] : wc_attribute_label( $attr_name, $product );
			$option_values[] = mpe_term_or_value( $attr_name, $value );
		}
		$variants[] = mpe_variant_from_product( $variation, $option_names, $option_values );
	}

	return $variants;
}

function mpe_term_or_value( $attribute_name, $value ) {
	if ( 0 === strpos( $attribute_name, 'pa_' ) && $value ) {
		$term = get_term_by( 'slug', $value, $attribute_name );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->name;
		}
	}
	return $value;
}

function mpe_variant_from_product( WC_Product $variant_product, $option_names, $option_values ) {
	$regular = (float) $variant_product->get_regular_price();
	$sale    = $variant_product->get_sale_price();
	$price   = '' !== $sale ? (float) $sale : $regular;
	$compare = ( '' !== $sale && (float) $sale < $regular ) ? $regular : '';

	$barcode = get_post_meta( $variant_product->get_id(), '_global_unique_id', true );
	if ( ! $barcode ) {
		$barcode = get_post_meta( $variant_product->get_id(), '_barcode', true );
	}

	$cost = get_post_meta( $variant_product->get_id(), '_wc_cog_cost', true );

	$variant_image = '';
	if ( $variant_product->get_image_id() ) {
		$variant_image = wp_get_attachment_image_url( $variant_product->get_image_id(), 'full' );
	}

	return array(
		'option1_name'      => isset( $option_names[0] ) ? $option_names[0] : '',
		'option1_value'     => isset( $option_values[0] ) ? $option_values[0] : '',
		'option2_name'      => isset( $option_names[1] ) ? $option_names[1] : '',
		'option2_value'     => isset( $option_values[1] ) ? $option_values[1] : '',
		'option3_name'      => isset( $option_names[2] ) ? $option_names[2] : '',
		'option3_value'     => isset( $option_values[2] ) ? $option_values[2] : '',
		'sku'               => $variant_product->get_sku(),
		'barcode'           => $barcode ?: '',
		'image'             => $variant_image ?: '',
		'weight'            => $variant_product->get_weight() ?: '',
		'weight_unit'       => get_option( 'woocommerce_weight_unit', 'kg' ),
		'price'             => $price,
		'compare_at_price'  => $compare,
		'cost'              => $cost ?: '',
		'taxable'           => ( 'taxable' === $variant_product->get_tax_status() ) ? 'TRUE' : 'FALSE',
		'inventory_tracker' => $variant_product->get_manage_stock() ? 'shopify' : '',
		'inventory_policy'  => $variant_product->backorders_allowed() ? 'continue' : 'deny',
		'requires_shipping' => $variant_product->is_virtual() ? 'FALSE' : 'TRUE',
		'inventory_qty'     => $variant_product->get_manage_stock() ? (int) $variant_product->get_stock_quantity() : 0,
	);
}

/**
 * ------------------------------------------------------------------
 * Stream the export: query products in batches, write rows, send file.
 *
 * IVB's store runs a persistent object cache (Redis/Memcached). Without
 * per-product cache cleanup, wc_get_product() accumulates cached post/meta
 * data for the whole request and exhausts PHP's memory limit on a full
 * catalog export — the same failure mode hit and fixed in ivb-shopify-export
 * for bulk order exports. Batch the query and flush the cache between
 * batches to avoid it here too.
 * ------------------------------------------------------------------
 */
function mpe_stream_export( $statuses ) {
	if ( ! class_exists( 'WooCommerce' ) ) {
		throw new Exception( 'WooCommerce no está activo.' );
	}

	$use_xlsx = class_exists( 'ZipArchive' );

	@set_time_limit( 0 );
	wp_raise_memory_limit( 'admin' );

	// Discard any accidental output (stray whitespace, notices, etc.) so headers
	// and the binary file aren't corrupted.
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	nocache_headers();
	$filename_base = 'matrixify-productos-' . gmdate( 'Y-m-d-His' );

	if ( $use_xlsx ) {
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename_base . '.xlsx"' );
		$writer = new MPE_XLSX_Writer();
		$writer->add_row( mpe_columns() );
		$write_row = function ( $row ) use ( $writer ) {
			$writer->add_row( $row );
		};
	} else {
		// Fallback: ZipArchive isn't available on this host, export CSV instead
		// (Matrixify also accepts CSV imports with the same column headers).
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename_base . '.csv"' );
		echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel opens accents correctly.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, mpe_columns() );
		$write_row = function ( $row ) use ( $out ) {
			fputcsv( $out, $row );
		};
	}

	$batch_size = MPE_EXPORT_BATCH_SIZE;
	$paged      = 1;

	do {
		$query = new WP_Query( array(
			'post_type'      => array( 'product' ),
			'post_status'    => $statuses,
			'posts_per_page' => $batch_size,
			'paged'          => $paged,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			foreach ( mpe_build_product_rows( $product ) as $row ) {
				$write_row( $row );
			}
			clean_post_cache( $product_id );
		}

		$has_more = $paged < $query->max_num_pages;
		$paged++;

		wp_cache_flush();
	} while ( $has_more );

	if ( $use_xlsx ) {
		$writer->output();
	} else {
		fclose( $out );
	}
}

/**
 * ------------------------------------------------------------------
 * Minimal dependency-free XLSX writer (single sheet, no external libs)
 * ------------------------------------------------------------------
 */
class MPE_XLSX_Writer {

	private $tmp_path;
	private $handle;
	private $row_count = 0;

	public function __construct() {
		$this->tmp_path = wp_tempnam( 'mpe-sheet' );
		$this->handle   = fopen( $this->tmp_path, 'w' );
		fwrite( $this->handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' );
	}

	public function add_row( array $cells ) {
		$this->row_count++;
		$xml = '<row r="' . $this->row_count . '">';
		$col = 1;
		foreach ( $cells as $value ) {
			$ref = $this->cell_ref( $col, $this->row_count );
			if ( is_numeric( $value ) && '' !== $value && ! preg_match( '/^0[0-9]/', (string) $value ) ) {
				$xml .= '<c r="' . $ref . '"><v>' . esc_html( $value ) . '</v></c>';
			} else {
				$xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escape( (string) $value ) . '</t></is></c>';
			}
			$col++;
		}
		$xml .= '</row>';
		fwrite( $this->handle, $xml );
	}

	private function cell_ref( $col, $row ) {
		$letters = '';
		while ( $col > 0 ) {
			$mod     = ( $col - 1 ) % 26;
			$letters = chr( 65 + $mod ) . $letters;
			$col     = intval( ( $col - $mod ) / 26 );
		}
		return $letters . $row;
	}

	private function escape( $value ) {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	public function output() {
		fwrite( $this->handle, '</sheetData></worksheet>' );
		fclose( $this->handle );

		$zip_path = wp_tempnam( 'mpe-xlsx' );
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::OVERWRITE );

		$zip->addFromString( '[Content_Types].xml', $this->content_types_xml() );
		$zip->addFromString( '_rels/.rels', $this->rels_xml() );
		$zip->addFromString( 'xl/workbook.xml', $this->workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels_xml() );
		$zip->addFromString( 'xl/styles.xml', $this->styles_xml() );
		$zip->addFile( $this->tmp_path, 'xl/worksheets/sheet1.xml' );

		$zip->close();

		readfile( $zip_path );

		@unlink( $this->tmp_path );
		@unlink( $zip_path );
	}

	private function content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
	}

	private function rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private function workbook_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	private function workbook_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	private function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
			. '<cellXfs count="1"><xf/></cellXfs>'
			. '</styleSheet>';
	}
}
