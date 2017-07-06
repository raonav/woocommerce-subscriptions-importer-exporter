<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h3>Importing Results</h3>

<br class="clear">
<ul class="subsubsub">
	<li class="wcsi-status-li" data-value="all"><a href="#"><?php esc_html_e( 'All', 'wcs-import-export' ); ?></a><span id="wcsi-all-count">(0)</span></li>
	<li class="wcsi-status-li" data-value="warning"> | <a href="#"><?php esc_html_e( 'Warnings', 'wcs-import-export' ); ?></a><span id="wcsi-warning-count">(0)</span></li>
	<li class="wcsi-status-li" data-value="failed"> | <a href="#"><?php esc_html_e( 'Failed', 'wcs-import-export' ); ?></a><span id="wcsi-failed-count">(0)</span></li>
</ul>
<table id="wcsi-progress" class="widefat_importer widefat">
	<thead>
		<tr>
			<th class="row">Import Status</th>
			<th class="row">Subscription</th>
			<th class="row">Items</th>
			<th class="row">Customer</th>
			<th class="row">Subscription Status</th>
			<th class="row">Number of Warnings</th>
		</tr>
	</thead>
	<tfoot>
		<tr class="importer-loading">
			<td colspan="6"></td>
		</tr>
	</tfoot>
	<tbody id="wcsi-all-tbody"></tbody>
	<tbody id="wcsi-warning-tbody" style="display: none;"></tbody>
	<tbody id="wcsi-failed-tbody" style="display: none;"></tbody>
</table>
<p id="wcsi-completed-message" style="display: none;">
	<?php echo wp_kses( sprintf( __( 'Import Complete! %1$sView Subscriptions%2$s or %3$sImport another file%4$s.', 'wcs-import-export' ), '<a href="' . esc_url( admin_url( 'edit.php?post_type=shop_subscription' ) ) . '">', '</a>', '<a href="' . esc_url( $this->admin_url ) . '">', '</a>' ), array( 'a' => array( 'href' => true ) ) ); ?>
</p>
