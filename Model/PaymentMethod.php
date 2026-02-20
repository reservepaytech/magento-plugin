<?php

namespace Reservepay\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;

class PaymentMethod extends AbstractMethod
{
  protected $_code = 'reservepay_payment';

  protected $_isInitializeNeeded = true;
  protected $_canUseInternal = false;
  protected $_canUseCheckout = true;
}
