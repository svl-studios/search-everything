<?php

defined( 'ABSPATH' ) || exit;

?>
<div class="updated se-updated" id="se-top-global-notice" >
	<div class="se-dismiss"><a href="<?php echo esc_url( $close_url ); ?>">Dismiss and go to settings</a></div>
	<h3><?php echo wp_kses_post( $notice['title'] ); ?></h3>
	<p class="se-about-description"><?php echo wp_kses_post( $notice['message'] ); ?></p>
</div>
