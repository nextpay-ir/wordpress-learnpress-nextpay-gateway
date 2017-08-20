<?php
/**
 * Display settings for payments
 *
 * @author  ThimPress
 * @package LearnPress/Admin/Views
 * @version 1.0
 */

if ( !defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
$settings = LP()->settings;
?>
<?php do_action( 'learn_press_before_' . $this->id . '_' . $this->section['id'] . '_settings_fields', $settings ); ?>
	<tr>
		<th scope="row"><label for="learn_press_nextpay_enable"><?php _e( 'Enable', 'learnpress' ); ?></label></th>
		<td>
			<input type="hidden" name="<?php echo $this->get_field_name( 'nextpay_enable' ); ?>" value="no">
			<input type="checkbox" id="learn_press_nextpay_enable" name="<?php echo $this->get_field_name( 'nextpay_enable' ); ?>" value="yes" <?php checked( $settings->get( 'nextpay_enable', 'yes' ) == 'yes', true ); ?> />
		</td>
	</tr>

	<tr data-learn_press_nextpay_enable="yes">
		<th scope="row"><label for="learn_press_nextpay_key"><?php _e( 'Api Key', 'learnpress' ); ?></label>
		</th>
		<td>
			<input type="text" class="regular-text" name="<?php echo $this->get_field_name( 'nextpay_key' ); ?>" value="<?php echo $settings->get( 'nextpay_key', '' ); ?>" />
		</td>
	</tr>


<?php do_action( 'learn_press_after_' . $this->id . '_' . $this->section['id'] . '_settings_fields', $settings ); ?>
