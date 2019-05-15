<?php
/**
 * Mailchimp for Craft Commerce
 *
 * @link      https://ethercreative.co.uk
 * @copyright Copyright (c) 2019 Ether Creative
 */

namespace ether\mc\services;

use Craft;
use craft\base\Component;
use craft\commerce\Plugin as Commerce;
use craft\errors\ElementNotFoundException;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use ether\mc\helpers\AddressHelper;
use ether\mc\MailchimpCommerce;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Class StoreService
 *
 * @author  Ether Creative
 * @package ether\mc\services
 */
class StoreService extends Component
{

	/**
	 * Generates a unique store ID and saves it in the plugin settings
	 *
	 * @throws Exception
	 */
	public function setStoreId ()
	{
		$i = MailchimpCommerce::$i;

		if ($i->getSettings()->storeId)
			return;

		Craft::$app->getPlugins()->savePluginSettings($i, [
			'storeId' => Craft::$app->getSecurity()->generateRandomString(),
		]);
	}

	/**
	 * Creates the Mailchimp store
	 *
	 * @param $listId
	 *
	 * @return boolean
	 * @throws ElementNotFoundException
	 * @throws Exception
	 * @throws InvalidConfigException
	 * @throws SiteNotFoundException
	 * @throws Throwable
	 */
	public function create ($listId)
	{
		$i = MailchimpCommerce::$i;

		if ($i->getSettings()->listId)
		{
			Craft::error('You can\'t change the list ID', 'mailchimp-commerce');
			return false;
		}

		Craft::$app->getPlugins()->savePluginSettings($i, [
			'listId' => $listId,
		]);

		list($success, $data, $error) = MailchimpCommerce::$i->chimp->post(
			'ecommerce/stores',
			$this->_buildStoreData($listId)
		);

		if ($error)
			Craft::error($error, 'mailchimp-commerce');

		return $success;
	}

	/**
	 * Updates the current store
	 *
	 * TODO: Trigger if store name or email changes
	 * TODO: Allow manual trigger for domain name change
	 *   (i.e. moving from dev to live)
	 *
	 * @return mixed
	 * @throws ElementNotFoundException
	 * @throws Exception
	 * @throws InvalidConfigException
	 * @throws SiteNotFoundException
	 * @throws Throwable
	 */
	public function update ()
	{
		$id = MailchimpCommerce::$i->getSettings()->storeId;

		list($success, $data, $error) = MailchimpCommerce::$i->chimp->patch(
			'ecommerce/stores/' . $id,
			$this->_buildStoreData()
		);

		if ($error)
			Craft::error($error, 'mailchimp-commerce');

		return $success;
	}

	/**
	 * Deletes the store from Mailchimp (include all synced products, orders, etc.)
	 */
	public function delete ()
	{
		MailchimpCommerce::$i->chimp->delete(
			'ecommerce/stores/' . MailchimpCommerce::$i->getSettings()->storeId
		);
	}

	// Helpers
	// =========================================================================

	/**
	 * Build the store data for syncing
	 *
	 * @param null|string $listId - Should only be set when creating a store
	 *
	 * @return array
	 * @throws SiteNotFoundException
	 * @throws Throwable
	 * @throws ElementNotFoundException
	 * @throws Exception
	 * @throws InvalidConfigException
	 */
	private function _buildStoreData ($listId = null)
	{
		$primarySite = Craft::$app->getSites()->getPrimarySite();
		$dummyCart   = Commerce::getInstance()->getCarts()->getCart();

		$id = MailchimpCommerce::$i->getSettings()->storeId;

		$storeData = [
			'id'            => $id,
			'platform'      => 'Craft Commerce',
			'name'          => $this->getStoreName(),
			'domain'        => $primarySite->getBaseUrl(),
			'email'         => $this->_getStoreEmail(),
			'currency_code' => $dummyCart->getPaymentCurrency(),
		];

		if ($listId)
			$storeData['list_id'] = $listId;

		$storeLocation = Commerce::getInstance()->getAddresses()->getStoreLocationAddress();

		if ($storeLocation)
		{
			$storeData['address'] = AddressHelper::asArray($storeLocation);
			$storeData['phone'] = $storeLocation->phone;
		}

		return $storeData;
	}

	/**
	 * Get the name of the store (or primary site)
	 *
	 * @return string|null
	 * @throws SiteNotFoundException
	 */
	public function getStoreName ()
	{
		$commerceSettings = Commerce::getInstance()->getSettings();
		$primarySite = Craft::$app->getSites()->getPrimarySite();

		return $commerceSettings->emailSenderName
			?: $commerceSettings->emailSenderNamePlaceholder
			?: $primarySite->name;
	}

	/**
	 * Get the stores email address
	 *
	 * @return string|null
	 */
	private function _getStoreEmail ()
	{
		$commerceSettings = Commerce::getInstance()->getSettings();

		return $commerceSettings->emailSenderAddress
			?: $commerceSettings->emailSenderAddressPlaceholder
			?: App::mailSettings()->fromEmail;
	}

}