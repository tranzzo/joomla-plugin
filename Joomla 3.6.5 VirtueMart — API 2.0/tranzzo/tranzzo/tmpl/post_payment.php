<?php
defined ('_JEXEC') or die();
?>
<div class="post_payment_payment_name" style="width: 100%">
	<span class="post_payment_payment_name_title"><?php echo vmText::_('VMPAYMENT_TRANZZO_PAYMENT_INFO'); ?> </span>
	<?php echo  $viewData["payment_name"]; ?>
</div>

<div class="post_payment_order_number" style="width: 100%">
	<span class="post_payment_order_number_title"><?php echo vmText::_('VMPAYMENT_TRANZZO_ORDER_NUMBER'); ?> </span>
	<?php echo  $viewData["order_number"]; ?>
</div>

<div class="post_payment_order_total" style="width: 100%">
	<span class="post_payment_order_total_title"><?php echo vmText::_('VMPAYMENT_TRANZZO_ORDER_TOTAL'); ?> </span>
	<?php echo  $viewData['displayTotalInPaymentCurrency']; ?>
</div>






