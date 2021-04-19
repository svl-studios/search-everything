<?php

defined( 'ABSPATH' ) || exit;

?>
<div class="error se-error-box">
	<p>Oops, there are errors in your submit:</p>
	<ul>
		<?php foreach ( $errors as $field => $message ) { ?>
			<li><?php echo wp_kses_post( sprintf( $message, $fields[ $field ] ) ); ?></li>
		<?php } ?>
	</ul>
	<p>Please go <a href="#" class="se-back">back</a> and check your settings again.</p>
</div>
