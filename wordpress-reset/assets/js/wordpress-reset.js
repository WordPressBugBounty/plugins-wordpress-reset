/**
 * WordPress Reset - Admin JavaScript
 *
 * @package wordpress-reset
 */

( function () {
	'use strict';

	/**
	 * Initialize when DOM is ready.
	 */
	function init() {
		var submitButton = document.getElementById( 'wordpress_reset_submit' );

		if ( ! submitButton ) {
			return;
		}

		submitButton.addEventListener( 'click', function ( e ) {
			var confirmField = document.getElementById(
				'wordpress_reset_confirm'
			);
			var resetField = document.getElementById( 'wordpress_reset' );

			if ( 'reset' === confirmField.value.trim() ) {
				var reset = confirm( wpResetData.confirmMessage );
				if ( reset ) {
					document.getElementById( 'wordpress_reset_form' ).submit();
				} else {
					resetField.value = 'false';
					e.preventDefault();
					return false;
				}
			} else {
				alert( wpResetData.alertMessage );
				e.preventDefault();
				return false;
			}
		} );
	}

	// Wait for DOM to be ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
