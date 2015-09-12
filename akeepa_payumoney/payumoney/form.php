<?php defined('_JEXEC') or die(); ?>
<?php
$t1 = JText::_('COM_AKEEBASUBS_LEVEL_REDIRECTING_HEADER');
$t2 = JText::_('COM_AKEEBASUBS_LEVEL_REDIRECTING_BODY');
?>

<h3><?php echo JText::_('COM_AKEEBASUBS_LEVEL_REDIRECTING_HEADER') ?></h3>
<p><?php echo JText::_('COM_AKEEBASUBS_LEVEL_REDIRECTING_BODY') ?></p>
<p align="center">
<form action="<?php echo $data->url ?>"  method="post" id="paymentForm">
	<input type="hidden" name="key" value="<?php echo $data->key ?>" />
	<input type="hidden" name="txnid" value="<?php echo $data->txnid ?>" />
	<input type="hidden" name="amount" value="<?php echo  $data->amount; ?>" />

		<input type="hidden" name="productinfo" value="<?php echo $data->product_info; ?>" />
		
	<input type="hidden" name="firstname" value="<?php echo $data->firstname ?>" />
	<input type="hidden" name="lastname" value="<?php echo $data->lastname ?>" />
	
	<input type="hidden" name="address1" value="<?php echo $kuser->address1 ?>">
	<input type="hidden" name="address2" value="<?php echo $kuser->address2 ?>">
	<input type="hidden" name="city" value="<?php echo $kuser->city ?>">
	<input type="hidden" name="state" value="<?php echo $kuser->state ?>">
	<input type="hidden" name="zipcode" value="<?php echo $kuser->zip ?>">
	<input type="hidden" name="country" value="<?php echo $kuser->country ?>">
	
	<input type="hidden" name="furl" value="<?php echo $data->success ?>" />
	<input type="hidden" name="curl" value="<?php echo $data->cancel ?>" />
	<input type="hidden" name="surl" value="<?php echo $data->postback ?>" />
	<input type="hidden" name="hash" value="<?php echo $data->hash;?>" />
	<input type="hidden" name="Pg" value="<?php echo $data->pg; ?>" />
	<input type="hidden" name="service_provider" value="<?php echo $data->service_provider; ?>
	
	<input type="hidden" name="custom" value="<?php echo $data->txnid ?>" />

	<input type="hidden" name="item_number" value="<?php echo $level->akeebasubs_level_id ?>" />
	<input type="hidden" name="item_name" value="<?php echo $level->title . ' - [ ' . $user->username . ' ]' ?>" />
	<input type="hidden" name="currency_code" value="<?php echo $data->currency ?>" />

	<input type="submit" value="<?php echo JText::_('PLG_AKPAYMENT_PAYUMONEY_PAY'); ?>" id="paypalsubmit" />
	
</form>
</p>