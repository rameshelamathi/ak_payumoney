<?php
/**
 * @package        akeebasubs
 * @copyright      Copyright (c)2010-2015 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license        GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

use Akeeba\Subscriptions\Admin\Model\Levels;
use Akeeba\Subscriptions\Admin\Model\Subscriptions;
use Akeeba\Subscriptions\Admin\PluginAbstracts\AkpaymentBase;
use Akeeba\Subscriptions\Admin\Helper\ComponentParams;

class plgAkpaymentPayumoney extends AkpaymentBase
{
	public function __construct(&$subject, $config = array())
	{
		$config = array_merge($config, array(
			'ppName'  => 'payumoney',
			'ppKey'   => 'PLG_AKPAYMENT_PAYUMONEY_TITLE',
			'ppImage' => 'https://www.paypal.com/en_US/i/bnr/horizontal_solution_PPeCheck.gif'
		));

		parent::__construct($subject, $config);
	}

	/**
	 * Returns the payment form to be submitted by the user's browser. The form must have an ID of
	 * "paymentForm" and a visible submit button.
	 *
	 * @param   string        $paymentmethod The currently used payment method. Check it against $this->ppName.
	 * @param   JUser         $user          User buying the subscription
	 * @param   Levels        $level         Subscription level
	 * @param   Subscriptions $subscription  The new subscription's object
	 *
	 * @return  string  The payment form to render on the page. Use the special id 'paymentForm' to have it
	 *                  automatically submitted after 5 seconds.
	 */
	public function onAKPaymentNew($paymentmethod, JUser $user, Levels $level, Subscriptions $subscription)
	{
		if ($paymentmethod != $this->ppName)
		{
			return false;
		}

		$nameParts = explode(' ', $user->name, 2);
		$firstName = $nameParts[0];

		$lastName = '';

		if (count($nameParts) > 1)
		{
			$lastName = $nameParts[1];
		}

		$slug = $level->slug;

		$rootURL = rtrim(JURI::base(), '/');
		$subpathURL = JURI::base(true);

		if (!empty($subpathURL) && ($subpathURL != '/'))
		{
			$rootURL = substr($rootURL, 0, -1 * strlen($subpathURL));
		}

		$data = (object)array(
			'url'       => $this->getPaymentURL(),
			'key'  => $this->getMerchantID(),
			'txnid'  => $subscription->akeebasubs_subscription_id,
			'amount' => $subscription->gross_amount,	
			'postback'  => $this->getPostbackURL(),
			'success'   => $rootURL . str_replace('&amp;', '&', JRoute::_('index.php?option=com_akeebasubs&view=Message&slug=' . $slug . '&task=thankyou&subid=' . $subscription->akeebasubs_subscription_id)),
			'cancel'    => $rootURL . str_replace('&amp;', '&', JRoute::_('index.php?option=com_akeebasubs&view=Message&slug=' . $slug . '&task=cancel&subid=' . $subscription->akeebasubs_subscription_id)),
			'currency'  => strtoupper(ComponentParams::getParam('currency', 'EUR')),
			'firstname' => $firstName,
			'lastname'  => $lastName,
			'productinfo'  => $level->title,
			'pg'  => 'CC',
			'service_provider'  => 'payu_paisa',
			// If there's a signup fee set 'recurring' to 2
			'recurring' => $level->recurring ? ($subscription->recurring_amount >= 0.01 ? 2 : 1) : 0
		);

		$kuser = $subscription->user;

		if (is_null($kuser))
		{
			/** @var \Akeeba\Subscriptions\Site\Model\Users $userModel */
			$userModel = $this->container->factory->model('Users')->tmpInstance();
			$kuser = $userModel->user_id($subscription->user_id)->firstOrNew();
		}
		
		//generate hash
		$salt = $this->getSalt();
		$data->hash=hash('sha512', $data->key.'|'.$data->txnid.'|'.$data->amount.'|'.$data->productinfo.'|'.$data->firstname.'|'.$kuser->email.'|||||||||||'.$salt);
	
		@ob_start();
		include dirname(__FILE__) . '/payumoney/form.php';
		$html = @ob_get_clean();

		return $html;
	}

	/**
	 * Processes a callback from the payment processor
	 *
	 * @param   string $paymentmethod The currently used payment method. Check it against $this->ppName
	 * @param   array  $data          Input (request) data
	 *
	 * @return  boolean  True if the callback was handled, false otherwise
	 */
	public function onAKPaymentCallback($paymentmethod, $data)
	{
		JLoader::import('joomla.utilities.date');

		// Check if we're supposed to handle this
		if ($paymentmethod != $this->ppName)
		{
			return false;
		}
		
		// Check IPN data for validity (i.e. protect against fraud attempt)
		$isValid = $this->isValidIPN($data);

		if (!$isValid)
		{
			$data['akeebasubs_failure_reason'] = $data['error_Message'];
		}

		// Check txn_type; we only accept web_accept transactions with this plugin
		$recurring = false;

		// Load the relevant subscription row
		if ($isValid)
		{
			$id = array_key_exists('txnid', $data) ? (int)$data['txnid'] : -1;
			$subscription = null;

			if ($id > 0)
			{
				/** @var Subscriptions $subscription */
				$subscription = $this->container->factory->model('Subscriptions')->tmpInstance();
				$subscription->find($id);

				if (($subscription->akeebasubs_subscription_id <= 0) || ($subscription->akeebasubs_subscription_id != $id))
				{
					$subscription = null;
					$isValid = false;
				}
			}
			else
			{
				$isValid = false;
			}

			/** @var Subscriptions $subscription */

			if (!$isValid)
			{
				$data['akeebasubs_failure_reason'] = 'The referenced subscription ID ("txnid" field) is invalid';
			}
		}

		/** @var Subscriptions $subscription */

		if ($isValid)
		{
			$mc_gross = floatval($data['amount']);

			// @todo On recurring subscriptions recalculate the net, tax and gross price by removing the signup fee
			if ($recurring && ($subscription->recurring_amount >= 0.01))
			{
				$gross = $subscription->recurring_amount;
			}
			else
			{
				$gross = $subscription->gross_amount;
			}

			if ($mc_gross > 0)
			{
				// A positive value means "payment". The prices MUST match!
				// Important: NEVER, EVER compare two floating point values for equality.
				$isValid = ($gross - $mc_gross) < 0.01;
			}

			if (!$isValid)
			{
				$data['akeebasubs_failure_reason'] = 'Paid amount does not match the subscription amount';
			}
		}
		
		//validate the hash
		if( $isValid &&  isset($data['status']) && isset($data["additionalCharges"])) {
			$retHashSeq = $data["additionalCharges"].'|'.$this->_salt.'|'.$data['status'].'|||||||||||'.$data['email'].'|'.$data['firstname'].'|'.$data['productinfo'].'|'.$data['amount'].'|'.$data['txnid'].'|'.$data['key'];
		}else{
			$retHashSeq = $this->_salt.'|'.$data['status'].'|||||||||||'.$data['email'].'|'.$data['firstname'].'|'.$data['productinfo'].'|'.$data['amount'].'|'.$data['txnid'].'|'.$data['key'];
		}
		$hash = hash("sha512", $retHashSeq);
		if (!isset($data['hash']) && $hash != $data['hash']) {
			$isValid = false;
			$data['akeebasubs_failure_reason'] = JText::_('Hash does not match');
		}

		// Log the IPN data
		$this->logIPN($data, $isValid);

		// Fraud attempt? Do nothing more!
		if (!$isValid)
		{
			return false;
		}

		// Check the payment_status
		switch ($data['status'])
		{
			case 'success':
				$newStatus = 'C';
				break;

			case 'pending':			
				$newStatus = 'P';
				break;

			case 'failure':
			case 'Denied':
			case 'Expired':
			case 'Failed':
			case 'Refunded':
			case 'Reversed':
			case 'Voided':
			default:
				$newStatus = 'X';
				break;
		}

		// Update subscription status (this also automatically calls the plugins)
		$updates = array(
			'akeebasubs_subscription_id' => $id,
			'processor_key'              => $data['mihpayid'],
			'state'                      => $newStatus,
			'enabled'                    => 0
		);

		JLoader::import('joomla.utilities.date');

		if ($newStatus == 'C')
		{
			self::fixSubscriptionDates($subscription, $updates);
		}

		// In the case of a successful recurring payment, fetch the old subscription's data
		if ($recurring && ($newStatus == 'C') && ($subscription->state == 'C'))
		{
			// Fix the starting date if the payment was accepted after the subscription's start date. This
			// works around the case where someone pays by e-Check on January 1st and the check is cleared
			// on January 5th. He'd lose those 4 days without this trick. Or, worse, if it was a one-day pass
			// the user would have paid us and we'd never given him a subscription!
			$regex = '/^\d{1,4}(\/|-)\d{1,2}(\/|-)\d{2,4}[[:space:]]{0,}(\d{1,2}:\d{1,2}(:\d{1,2}){0,1}){0,1}$/';

			if (!preg_match($regex, $subscription->publish_up))
			{
				$subscription->publish_up = '2001-01-01';
			}

			if (!preg_match($regex, $subscription->publish_down))
			{
				$subscription->publish_down = '2038-01-01';
			}

			$jNow = new JDate();
			$jStart = new JDate($subscription->publish_up);
			$jEnd = new JDate($subscription->publish_down);
			$now = $jNow->toUnix();
			$start = $jStart->toUnix();
			$end = $jEnd->toUnix();

			// Create a new record for the old subscription
			$oldData = $subscription->getData();
			$oldData['akeebasubs_subscription_id'] = 0;
			$oldData['publish_down'] = $jNow->toSql();
			$oldData['enabled'] = 0;
			$oldData['contact_flag'] = 3;
			$oldData['notes'] = "Automatically renewed subscription on " . $jNow->toSql();

			// Calculate new start/end time for the subscription
			$allSubs = $subscription->tmpInstance()
				->paystate('C')
				->level($subscription->akeebasubs_level_id)
				->user_id($subscription->user_id)
				->get(true);

			$max_expire = 0;

			if ($allSubs->count())
			{
				foreach ($allSubs as $aSub)
				{
					$jExpire = new JDate($aSub->publish_down);
					$expire = $jExpire->toUnix();

					if ($expire > $max_expire)
					{
						$max_expire = $expire;
					}
				}
			}

			$duration = $end - $start;
			$start = max($now, $max_expire);
			$end = $start + $duration;
			$jStart = new JDate($start);
			$jEnd = new JDate($end);

			$updates['publish_up'] = $jStart->toSql();
			$updates['publish_down'] = $jEnd->toSql();

			// Save the record for the old subscription
			$table = $subscription->tmpInstance();
			$table->save($oldData);
		}
		elseif ($recurring && ($newStatus != 'C'))
		{
			// Recurring payment, but payment_status is not Completed. We have
			// stop right now and not save the changes. Otherwise the status of
			// the subscription will become P or X and the recurring payment
			// code above will not run when PayPal sends us a new IPN with the
			// status set to Completed.
			return true;
		}
		// Save the changes
		$subscription->save($updates);

		// Run the onAKAfterPaymentCallback events
		$this->container->platform->importPlugin('akeebasubs');
		$this->container->platform->runPlugins('onAKAfterPaymentCallback', array(
			$subscription
		));

		return true;
	}

	/**
	 * Gets the form action URL for the payment
	 */
	private function getPaymentURL()
	{
		$sandbox = $this->params->get('sandbox', 0);

		if ($sandbox)
		{
			return 'https://test.payu.in/_payment.php';
		}
		else
		{
			return 'https://secure.payu.in/_payment.php';
		}
	}

	/**
	 * Gets the Merchant ID
	 */
	private function getMerchantID()
	{
		$sandbox = $this->params->get('sandbox', 0);

		if ($sandbox)
		{
			return $this->params->get('sandbox_merchant', '');
		}
		else
		{
			return $this->params->get('merchant', '');
		}
	}
	
	/**
	 * Gets the Salt 
	 */
	private function getSalt()
	{
		$sandbox = $this->params->get('sandbox', 0);
	
		if ($sandbox)
		{
			return $this->params->get('sandbox_salt', '');
		}
		else
		{
			return $this->params->get('salt', '');
		}
	}

	/**
	 * Creates the callback URL based on the plugins configuration.
	 */
	private function getPostbackURL()
	{

		$url = JURI::base() . 'index.php?option=com_akeebasubs&view=Callback&paymentmethod=payumoney';

		$configurationValue = $this->params->get('protocol', 'keep');
		$pattern = '/https?:\/\//';

		if ($configurationValue == 'secure')
		{
			$url = preg_replace($pattern, "https://", $url);
		}

		if ($configurationValue == 'insecure')
		{
			$url = preg_replace($pattern, "http://", $url);
		}

		return $url;
	}

	/**
	 * Validates the incoming data against PayPal's IPN to make sure this is not a
	 * fraudelent request.
	 */
	private function isValidIPN(&$data)
	{
		
		if(array_key_exists('error_Message', $data)) {
			return false;
		}
		return true;
	}
}