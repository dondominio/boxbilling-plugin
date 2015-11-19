<?php

/**
 * The DonDominio Registrar Module for BoxBilling.
 * @copyright Soluciones Corporativas IP S.L. 2015
 * @package DonDominioBoxBilling
 */

/**
 * The DonDominio API SDK Library
 * @link https://github.com/dondominio/dondominiophp
 */
require_once "lib/sdk/DonDominioAPI.php";

/**
 * The DonDominio Registrar Module for BoxBilling.
 * Allows BoxBilling to communicate with the DonDominio API to register,
 * transfer, and renew domains automatically.
 */
class Registrar_Adapter_DonDominio extends Registrar_AdapterAbstract
{
	/**
	 * DonDominio API Client.
	 * @var DonDominioAPI
	 */
	protected $client = null;
	
	/**
	 * Module configuration.
	 * @var array
	 */
	protected $config = array(
		'api_username' => null,
		'api_password' => null,
		'test_mode' => false,
		'override_owner' => "",
		'allow_update_owner' => false,
		'override_admin' => "",
		'override_tech' => "",
		'override_billing' => ""
	);
	
	/**
	 * Additional fields used to register special domains.
	 * @param array
	 */
	protected $additionalFields = array(
		'.aero' => array('aeroId', 'aeroPass'),
		'.cat' => array('domainIntendedUse'),
		'.pl' => array('domainIntendedUse'),
		'.scot' => array('domainIntendedUse'),
		'.eus' => array('domainIntendedUse'),
		'.gal' => array('domainIntendedUse'),
		'.quebec' => array('domainIntendedUse'),
		'.coop' => array('coopCVC'),
		'.fr' => array('ownerDateOfBirth', 'frTradeMark', 'frSirenNumber'),
		'.hk' => array('ownerDateOfBirth'),
		'.it' => array('ownerDateOfBirth', 'ownerPaceOfBirth'),
		'.jobs' => array
		(
			'jobsOwnerIsAssocMember', 'jobsOwnerWebsite', 'jobsOwnerTitle',
			'jobsOwnerIndustrytype', 'jobsAdminIsAssocMember', 'jobsAdminWebsite',
			'jobsAdminTitle', 'jobsAdminIndustrytype', 'jobsTechIsAssocMember',
			'jobsTechWebsite', 'jobsTechTitle', 'jobsTechIndustrytype',
			'jobsBillingIsAssocMember', 'jobsBillingWebsite', 'jobsBillingTitle',
			'jobsBillingIndustrytype'
		),
		'.lawyer' => array('coreContactInfo'),
		'.attorney' => array('coreContactInfo'),
		'.dentist' => array('coreContactInfo'),
		'.airforce' => array('coreContactInfo'),
		'.army' => array('coreContactInfo'),
		'.navy' => array('coreContactInfo'),
		'.ltda' => array('ltdaAuthority', 'ltdaLicenseNumber'),
		'.pro' => array
		(
			'proProfession', 'proAuthority', 'proAuthorityWebsite',
			'proLicenseNumber'
		),
		'.ru' => array('ownerDateOfBirth', 'ruIssuer', 'ruIssuerDate'),
		'.travel' => array('travelUIN'),
		'.xxx' => array('xxxClass', 'xxxName', 'xxxEmail', 'xxxId')
	);
	
	/**
	 * Initialize parameters & API client.
	 * @param array $options Options passed to the module
	 * @throws Registrar_Exception if API username or password is not set
	 */
	public function __construct($options)
	{		
		if(!extension_loaded('curl')) {
			throw new Registrar_Exception('CURL Extension is not enabled');
		}
		
		$this->config = array_merge(
			$this->config,
			(is_array($options)) ? $options : array()
		);
		
		if(empty($this->config['api_username']) || empty($this->config['api_password'])){
			throw new Registrar_Exception('DonDominio API Username & Password is needed to continue');
		}
		
		$this->client = new DonDominioAPI(array(
			'port' => 443,
			'apiuser' => $this->config['api_username'],
			'apipasswd' => $this->config['api_password'],
			'autoValidate' => true,
			'versionCheck' => true,
			'response' => array(
				'throwExceptions' => true
			),
			'userAgent' => array(
				'BoxBillingRegistrarPlugin' => $this->getVersion()
			)
		));
	}
	
	protected function getVersion()
	{
		$versionFile = __DIR__ . '/version.json';
		
		if( !file_exists( $versionFile )){
			return 'unknown';
		}
		
		$json = @file_get_contents( $versionFile );
		
		if( empty( $json )){
			return 'unknown';
		}
		
		$versionInfo = json_decode( $json, true );
		
		if( !is_array( $versionInfo ) || !array_key_exists( 'version', $versionInfo )){
			return 'unknown';
		}
		
		return $versionInfo['version'];
	}
	
	/**
	 * Returns all the config parameters used by this module.
	 * @return array
	 */
	public static function getConfig()
	{
		return array(
			'label' => 'Manages domains using the DonDominio/MrDomain API',
			'form' => array(
				'api_username' => array(
					'text',
					array(
						'label' => 'API USERNAME',
						'description' => 'API Username assigned to your DonDominio/MrDomain account'
					)
				),
				'api_password' => array(
					'password',
					array(
						'label' => 'API PASSWORD',
						'description' => 'API Password assigned to your DonDominio/MrDomain account'
					)
				),
				
				//Override contact information
				'override_owner' => array(
					'text',
					array(
						'label' => 'OVERRIDE OWNER CONTACT INFORMATION',
						'description' => '
							Enter a DonDominio Contact ID to use when registering or transferring
							domains instead of the customer contact information. A DonDominio
							Contact ID looks like this: AAA-00000. Find it in your domain details
							page on the DonDominio website.
						',
						'required' => false
					)
				),
				'allow_update_owner' => array(
					'checkbox',
					array(
						'label' => 'ALLOW UPDATES TO OWNER CONTACT',
						'description' => '
							When using the "Override Owner Contact Information" option, customers
							are not allowed to modify the contact information for a domain. Check
							this box to change this behaviour.
						',
						'required' => false
					)
				),
				
				'override_admin' => array(
					'text',
					array(
						'label' => 'OVERRIDE ADMIN CONTACT INFORMATION',
						'description' => '
							Enter a DonDominio Contact ID to use when registering or transferring
							domains instead of the customer contact information. A DonDominio
							Contact ID looks like this: AAA-00000. Find it in your domain details
							page on the DonDominio website.
						',
						'required' => false
					)
				),
				
				'override_tech' => array(
					'text',
					array(
						'label' => 'OVERRIDE TECH CONTACT INFORMATION',
						'description' => '
							Enter a DonDominio Contact ID to use when registering or transferring
							domains instead of the customer contact information. A DonDominio
							Contact ID looks like this: AAA-00000. Find it in your domain details
							page on the DonDominio website.
						',
						'required' => false
					)
				),
				
				'override_billing' => array(
					'text',
					array(
						'label' => 'OVERRIDE BILLING CONTACT INFORMATION',
						'description' => '
							Enter a DonDominio Contact ID to use when registering or transferring
							domains instead of the customer contact information. A DonDominio
							Contact ID looks like this: AAA-00000. Find it in your domain details
							page on the DonDominio website.
						',
						'required' => false
					)
				)
			)
		);
	}
	
	/**
	 * Returns the TLDs available to registrer with DonDominio.
	 * @return array
	 */
	public function getTlds()
	{
		return array(
			'.es', '.com', '.eu'
		);
	}
	
	/**
	 * Checks if a domain is available to register.
	 * @param Registrar_Domain $domain Domain to check
	 * @throws Registrar_Exception on API error
	 * @return boolean
	 */
	public function isDomainAvailable(Registrar_Domain $domain)
	{		
		try{
			$check = $this->client->domain_check(
				$domain->getName()
			);
			
			return $check->get("domains")[0]['available'];
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getError());
		}
		
		return false;
	}
	
	/**
	 * Indicates whether a domain can be transferred.
	 * @param Registrar_Domain $domain Domain to be checked
	 * @throws Registrar_Exception on API error
	 * @return boolean
	 */
	public function isDomainCanBeTransfered(Registrar_Domain $domain)
	{
		try{
			$check = $this->client->domain_checkForTransfer(
				$domain->getName()
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getError());
		}
		
		$transferable = $check->get("domains")[0]['transferavail'];
		
		if(!$transferable){
			throw new Registrar_Exception('Not available for transfer: ' . implode("\r\n", $check->get("domains")[0]['transfermsg']));
		}
		
		return true;
	}
	
	/**
	 * Update DNS Servers for a domain.
	 * @param Registrar_Domain $domain Domain to be updated
	 * @throws Registrar_Exception on API error
	 * @return boolean
	 */
	public function modifyNs(Registrar_Domain $domain)
	{
		try{
			$nameservers = $this->client->domain_updateNameServers(
				$domain->getSld() . $domain->getTld(),
				$domain->getNameservers()
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Returns information about a domain.
	 * @param Registrar_Domain $domain Domain to be checked
	 * @throws Registrar_Exception on API error
	 * @return Registrar_Domain
	 */
	public function getDomainDetails(Registrar_Domain $domain)
	{
		try{
			$details = $this->client->domain_getInfo(
				$domain->getSld() . $domain->getTld(),
				array(
					'infoType' => 'status'
				)
			);
			
			$contact = $this->client->domain_getInfo(
				$domain->getSld() . $domain->getTld(),
				array(
					'infoType' => 'contact'
				)
			);
			
			$nameservers = $this->client->domain_getInfo(
				$domain->getSld() . $domain->getTld(),
				array(
					'infoType' => 'nameservers'
				)
			);
			
			if($details->get("authcodeCheck") == true){
				$authcode = $this->client->domain_getInfo(
					$domain->getName(),
					array(
						'infoType' => 'authcode'
					)
				);
			}
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		$domain->setRegistrationTime(strtotime($details->get('tsCreate')));
		$domain->setExpirationTime(strtotime($details->get('tsExpir')));
		
		if($details->get("authcodeCheck") == true){
			$domain->setEpp($authcode->get('authcode'));
		}
		
		$domain->setPrivacyEnabled(($details->get('whoisPrivacy') == true));
		$domain->setLocked(($details->get('transferBlock') == true));
		
		//Contact Information
		$c = new Registrar_Domain_Contact();
		$dc = $contact->get("contactOwner");
		
		$phone_sep = explode(".", $dc['phone']);
		
		$c->setId($dc['contactID']);
		$c->setFirstName($dc['firstName']);
		$c->setLastName($dc['lastName']);
		$c->setEmail($dc['email']);
		$c->setCompany($dc['orgName']);
		$c->setTelCc($phone_sep[0]);
		$c->setTel($phone_sep[1]);
		$c->setAddress1($dc['address']);
		$c->setCity($dc['city']);
		$c->setCountry($dc['country']);
		$c->setState($dc['state']);
		$c->setZip($dc['postalCode']);
		
		$domain->setContactRegistrar($c);
		
		//Nameservers
		$nameservers_array = $nameservers->get("nameservers");
		
		if(!empty($nameservers_array[0])){
			$domain->setNs1($nameservers_array[0]['name']);
		}
		
		if(!empty($nameservers_array[1])){
			$domain->setNs2($nameservers_array[1]['name']);
		}
		
		if(!empty($nameservers_array[2])){
			$domain->setNs4($nameservers_array[2]['name']);
		}
		
		if(!empty($nameservers_array[3])){
			$domain->setNs4($nameservers_array[3]['name']);
		}
		
		return $domain;
	}
	
	/**
	 * Tries to delete a domain.
	 * The DonDominio API does not support deleting domains. This method will
	 * always result in an exception being thrown.
	 * @param Registrar_Domain $domain Domain to be deleted
	 * @throws Registrar_Exception always
	 */
	public function deleteDomain(Registrar_Domain $domain)
	{
		throw new Registrar_Exception('Deleting domains is not supported by the API. Please contact support.');
	}
	
	/**
	 * Attempts to register a domain.
	 * @param Registrar_Domain $domain Domain to register
	 * @throws Registrar_Exception if it fails to register the domain
	 * @return boolean
	 */
	public function registerDomain(Registrar_Domain $domain)
	{
		$additionalFields = $this->_getAdditionalFields($domain);
		
		$params = (is_array($additionalFields)) ? $additionalFields : array();
		
		$tld = $domain->getTld();
		$sld = $domain->getSld();
		
		$ns = array();
		$ns[] = $domain->getNs1();
		$ns[] = $domain->getNs2();
		
		if($domain->getNs3()){
			$ns[] = $domain->getNs3();
		}
		
		if($domain->getNs4()){
			$ns[] = $domain->getNs4();
		}
		
		//Is the override_owner parameter set?
		if(empty($this->config['override_owner'])){
			$contact = $domain->getContactRegistrar();
			
			//We need a VAT Number to continue
			if(strlen(trim($contact->getDocumentNr())) == 0 ){
				throw new Registrar_Exception('DonDominio Requires a VAT Number to register domains. Please update your information in your profile.');
			}
			
			//Owner contact information
			$params['ownerContactType'] = 'individual';
			$params['ownerContactFirstName'] = $contact->getFirstName();
			$params['ownerContactLastName'] = $contact->getLastName();
			$params['ownerContactIdentNumber'] = $contact->getDocumentNr();
			$params['ownerContactEmail'] = $contact->getEmail();
			$params['ownerContactPhone'] = str_replace('00', '+', $contact->getTelCc()) . '.' . $contact->getTel();
			$params['ownerContactAddress'] = $contact->getAddress1();
			$params['ownerContactPostalCode'] = $contact->getZip();
			$params['ownerContactCity'] = $contact->getCity();
			$params['ownerContactState'] = $contact->getState();
			$params['ownerContactCountry'] = $contact->getCountry();
		}else{
			//Using provided owner contact ID
			$params['ownerContactID'] = $this->config['override_owner'];
		}
		
		//Set Admin Contact ID, if specified
		if(!empty($this->config['override_admin'])){
			$params['adminContactID'] = $this->config['override_admin'];
		}
		
		//Set Tech Contact ID, if specified
		if(!empty($this->config['override_tech'])){
			$params['techContactID'] = $this->config['override_tech'];
		}
		
		//Set Billing Contact ID, if specified
		if(!empty($this->config['override_billing'])){
			$params['billingContactID'] = $this->config['override_admin'];
		}
		
		$params['nameservers'] = implode(',', $ns);
		$params['premium'] = false;
		$params['period'] = intval($domain->getRegistrationPeriod());
		
		try{
			$register = $this->client->domain_create(
				$sld . $tld,
				$params
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Attempts to transfer a domain.
	 * @param Registrar_Domain $domain Domain to transfer
	 * @throws Registrar_Exception if it fails to transfer the domain
	 * @return boolean
	 */
	public function transferDomain(Registrar_Domain $domain)
	{
		$additionalFields = $this->_getAdditionalFields($domain);
		
		$params = (is_array($additionalFields)) ? $additionalFields : array();
		
		$tld = $domain->getTld();
		$sld = $domain->getSld();
		
		$ns = array();
		$ns[] = $domain->getNs1();
		$ns[] = $domain->getNs2();
		
		if($domain->getNs3()){
			$ns[] = $domain->getNs3();
		}
		
		if($domain->getNs4()){
			$ns[] = $domain->getNs4();
		}
		
		//Is the override_owner parameter set?
		if(empty($this->config['override_owner'])){
			$contact = $domain->getContactRegistrar();
			
			//We need a VAT Number to continue
			if(strlen(trim($contact->getDocumentNr())) == 0 ){
				throw new Registrar_Exception('DonDominio Requires a VAT Number to register domains. Please update your information in your profile.');
			}
			
			//Owner contact information
			$params['ownerContactType'] = 'individual';
			$params['ownerContactFirstName'] = $contact->getFirstName();
			$params['ownerContactLastName'] = $contact->getLastName();
			$params['ownerContactIdentNumber'] = $contact->getDocumentNr();
			$params['ownerContactEmail'] = $contact->getEmail();
			$params['ownerContactPhone'] = $contact->getTel();
			$params['ownerContactAddress'] = $contact->getAddress1();
			$params['ownerContactPostalCode'] = $contact->getZip();
			$params['ownerContactCity'] = $contact->getCity();
			$params['ownerContactState'] = $contact->getState();
			$params['ownerContactCountry'] = $contact->getCountry();
		}else{
			//Using provided owner contact ID
			$params['ownerContactID'] = $this->config['override_owner'];
		}
		
		//Set Admin Contact ID, if specified
		if(!empty($this->config['override_admin'])){
			$params['adminContactID'] = $this->config['override_admin'];
		}
		
		//Set Tech Contact ID, if specified
		if(!empty($this->config['override_tech'])){
			$params['techContactID'] = $this->config['override_tech'];
		}
		
		//Set Billing Contact ID, if specified
		if(!empty($this->config['override_billing'])){
			$params['billingContactID'] = $this->config['override_admin'];
		}
		
		$params['nameservers'] = implode(',', $ns);
		$params['authcode'] = $domain->getEpp();
		
		try{
			$register = $this->client->domain_transfer(
				$sld . $tld,
				$params
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Attempts to renew a domain.
	 * @param Registrar_Domain $domain Domain to renew
	 * @throws Registrar_Exception if it fails to renew the domain
	 * @return boolean
	 */
	public function renewDomain(Registrar_Domain $domain)
	{
		try{
			$renew = $this->client->domain_renew(
				$domain->getName(),
				array(
					'curExpDate' => date('Ymd', $domain->getExpirationTime()),
					'period' => $domain->getRegistrationPeriod()
				)
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Attempts to modify contact information.
	 * If updating contact informartion has been disabled per module
	 * configuration, this method will result in an exception being thrown
	 * (and a message being shown to the user).
	 * @param Registrar_Domain $domain Domain to modify
	 * @throws Registrar_Exception if updating contacts is disabled
	 * @return boolean
	 */
	public function modifyContact(Registrar_Domain $domain)
	{
		throw new Registrar_Exception('Updating contacts is not supported. Please contact support.');
	}
	
	/**
	 * Enable anonymous Whois for a domain.
	 * @param Registrar_Domain $domain Domain to set whois status
	 * @throws Registrar_Exception on API error
	 * @return boolean
	 */
	public function enablePrivacyProtection(Registrar_Domain $domain)
	{
		try{
			$lock = $this->client->domain_update(
				$domain->getSld() . $domain->getTld(),
				array(
					'updateType' => 'whoisPrivacy',
					'whoisPrivacy' => true
				)
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Disable anonymous Whois for a domain.
	 * @param Registrar_Domain $domain Domain to set whois status
	 * @throws Registrar_Exception on API error
	 * @return boolean
	 */
	public function disablePrivacyProtection(Registrar_Domain $domain)
	{
		try{
			$lock = $this->client->domain_update(
				$domain->getSld() . $domain->getTld(),
				array(
					'updateType' => 'whoisPrivacy',
					'whoisPrivacy' => false
				)
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Get the authcode for a domain.
	 * @param Registrar_Domain $domain Domain to get Authcode
	 * @throws Registrar_Exception on API error
	 * @return string
	 */
	public function getEpp(Registrar_Domain $domain)
	{
		$authcode_string = '';
		
		try{
			$details = $this->client->domain_getInfo(
				$domain->getName(),
				array(
					'infoType' => 'status'
				)
			);
			
			if($details->get("authcodeCheck") == true){
				$authcode = $this->client->domain_getAuthCode($domain->getSld() . $domain->getTld());
				$authcode_string = $authcode->get("authcode");
			}
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		if(empty($authcode_string)){
			throw new Registrar_Exception("Transfer code for " . $domain->getName() . " is not available at this moment.");
		}
		
		return $authcode_string;
	}
	
	/**
	 * Set the transfer status to blocked for a domain.
	 * @param Registrar_Domain $domain Domain to lock
	 * @throws Registrar_Exception on API error
	 * @return boolean
	 */
	public function lock(Registrar_Domain $domain)
	{
		try{
			$lock = $this->client->domain_update(
				$domain->getSld() . $domain->getTld(),
				array(
					'updateType' => 'transferBlock',
					'transferBlock' => true
				)
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Set the transfer status to unblocked for a domain.
	 * @param Registrar_Domain $domain Domain to unlock
	 * @throws Registrar_Exception on API error
	 * @return boolean
	 */
	public function unlock(Registrar_Domain $domain)
	{
		try{
			$lock = $this->client->domain_update(
				$domain->getSld() . $domain->getTld(),
				array(
					'updateType' => 'transferBlock',
					'transferBlock' => false
				)
			);
		}catch(DonDominio_Error $e){
			throw new Registrar_Exception($e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Get additional fields for the domain.
	 * @param Registrar_Domain $domain
	 * @return array
	 */
	private function _getAdditionalFields(Registrar_Domain $domain)
	{
		//Getting order details
		$order = $this->_getFromDb($domain->getSld(), $domain->getTld());
		
		$order = json_decode($order);
		
		if(!array_key_exists($domain->getTld(), $this->additionalFields)){
			return false;
		}
		
		$additionalFields = $this->additionalFields[$domain->getTld()];
		
		$params = array();
		
		foreach($additionalFields as $field){
			if(array_key_exists($field, $order)){
				$params[$field] = $order[$field];
			}
		}
		
		return $params;
	}
	
	/**
	 * Get order information from Database.
	 * @param string $sld Domain SLD
	 * @param string $tld Domain TLD
	 * @return array
	 */
	private function _getFromDb($sld, $tld)
	{
		global $config;
		
		$dsn = $config['db']['type'] . ':dbname=' . $config['db']['name'] . ';host=' . $config['db']['host'] . ';charset=UTF8';
		
		try{
			$dbi = new PDO($dsn, $config['db']['user'], $config['db']['password']);
		}catch(PDOException $e){
			die("Connection to database failed: " . $e->getMessage());
		}
		
		$sql = "
			SELECT
				config
			FROM client_order
			WHERE
				title = :title
			LIMIT 0,1
		";
		
		$stat = $dbi->prepare($sql);
		
		$stat->execute(array(':title' => 'Domain ' . $sld . $tld . ' registration'));
		
		$result = $stat->fetchAll();
		
		return $result[0]['config'];
	}
}

?>
