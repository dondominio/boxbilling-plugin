<?php

/**
 * DonDominio Account Sincronization.
 * Gets all domains from a DonDominio account and attemps to sincronize
 * them with the local WHMCS database.
 * @copyright Soluciones Corporativas IP, SL 2015
 * @package DonDominioWHMCSImporter
 */

/**
 * DonDominio Account Sincronization.
 * Gets all domains from a DonDominio account and attemps to sincronize
 * them with the local WHMCS database.
 */
class DDSync
{
	/**
	 * Array of options used by this class.
	 * @var array
	 */
	protected $options = array(
		'apiClient' => null,
		'clientId' => '',
		'dryrun' => false,
		'forceClientId' => false
	);
	
	/**
	 * Initialize sync.
	 * Applies the options provided and checks for any missing
	 * or invalid parameters.
	 * @param array $options Options
	 */
	public function __construct(array $options = array())
	{
		$this->options = array_merge(
			$this->options,
			(is_array($options)) ? $options : array()
		);
		
		Output::debug("Checking DonDominio API Client");
		if(!$this->options['apiClient'] instanceOf DonDominioAPI){
			Output::error("API Client is not a valid DonDominioAPI instance.");
			exit();
		}
		
		Output::debug("Checking valid Client ID");
		if(!$this->options['clientId']){
			Output::error("You must specify a valid Client ID to continue.");
		}
		
		Output::debug("Searching Client ID in database");
		if(!$this->findUser()){
			Output::error('Client could not be found. Provide a valid Client ID using the --uid parameter.');
		}
		
		if($this->options['dryrun']){
			Output::debug("--dry flag found, enabling Dry Run mode");
			Output::line("*** DRY RUN MODE ***");
			Output::line("No changes will be made to your database.");
		}
	}
	
	/**
	 * Sync domains.
	 * Creates all missing domains in the local database comparing it against
	 * the DonDominio account associated to the API Username provided.
	 *
	 * Does not return anything, writes directly to output.
	 */
	public function sync()
	{
		Output::debug("Sync start");
		
		$total = $results = $created = $exists = $error = 0;
		
		$error_list = array();
		
		Output::debug("Getting domains from API");
		
		$domains = $this->getDomainsFromAPI();
		
		$order_created = false;
		
		$i = 1;
		
		Output::debug("Looping through " . count($domains) . " domains");
		
		foreach($domains as $domain){			
			if($this->domainExists($domain['name'])){
				Output::debug("Updating domain information for " . $domain['name']);
				
				if(!$this->options['dryrun']){
					$update = $this->updateDomain($domain);
					
					if($update !== true){
						$error_list[] = $domain['name'] . ":" . $update;
					}else{
						Output::line(str_pad($domain['name'], 30, " ") . "Updated");
					}
				}else{
					Output::line(str_pad($domain['name'], 30, " ") . "Updated");
				}
								
				$exists++;
			}else{
				if(!$this->options['sync']){
					if(!$this->tldExists($domain['tld'])){
						Output::line(str_pad($domain['name'], 30, " ") . "TLD not configured (" . $domain['tld'] . ")");
						
						$error_list['tld_' . $domain['tld'] . '_notfound'] = 'You need to configure the "' . $domain['tld'] . '" TLD in WHMCS to sync .' . $domain['tld'] . ' domains.';
						
						$error++;
					}else{
						Output::debug("Searching domain owner for " . $domain['name']);
						
						$user = ($this->options['forceClientId']) ? $this->options['clientId'] : $this->findDomainOwner($domain['name']);
						
						if(!$user){
							$user = $this->options['clientId'];
						}
						
						if(!$this->options['dryrun']){
							Output::debug("Creating domain " . $domain['name']);
							
							$create = $this->createDomain($domain);
							
							if($create !== true){
								$error_list[] = $domain['name'] . ": " . $create;
							}else{
								Output::line(str_pad($domain['name'], 30, " ") . "Created");
							}
						}else{
							Output::line(str_pad($domain['name'], 30, " ") . "Created");
						}
						
						$created++;
					}
				}
			}
			
			$i++;
		}
		
		Output::line("");
		Output::line("Sync finished.");
		Output::line("$created created - $exists updated - $error errors found");
		Output::line("");
		
		if(count($error_list)){
			Output::line("The following errors where found:");
			
			foreach($error_list as $error){
				Output::line(" - " . $error);
			}
		}
	}
	
	/**
	 * Get the domain list from the API.
	 * Returns an array containing all the domains in the user account.
	 * @return array
	 */
	protected function getDomainsFromAPI()
	{
		$dondominio = $this->options['apiClient'];
		
		$domains = array();
		
		do{
			try{				
				$list = $dondominio->domain_list();
							
				$info = $list->get("queryInfo");
								
				$results = $info['results'];
				$total = $info['total'];
				
				$domainList = $list->get("domains");
				
				$domains = array_merge(
					$domains,
					$domainList
				);
			}catch(DonDominioAPI_Error $e){
				Output::line("");
				Output::line("There was an error fetching information: " . $e->getMessage());
				
				break;
			}
		}while(count($domains) < $total);
		
		return $domains;
	}
	
	/**
	 * Find an user in the database.
	 * Returns true if the user exists, false otherwise.
	 * @return boolean
	 */
	protected function findUser()
	{
		$uid = $this->options['clientId'];
		
		$user = full_query("SELECT id FROM client WHERE id = '" . $uid . "'");
				
		if(count($user) != 1){
			return false;
		}
		
		return true;
	}
	
	/**
	 * Check if a domain already exists.
	 * Returns true if the domain exists, false otherwise.
	 * @param string $cname Domain
	 * @return boolean
	 */
	protected function domainExists($cname)
	{
		$query = full_query("SELECT id FROM service_domain WHERE CONCAT(sld, tld) = '" . $cname . "'");
				
		if(!is_array($query) || count($query) == 0){
			return false;
		}
		
		return true;
	}
	
	/**
	 * Check if a TLD exists and is configured to work with DonDominio.
	 * Returns true if the TLD exists, false otherwise.
	 * @param string $tld TLD
	 * @return boolean
	 */
	protected function tldExists($tld)
	{
		$checkTLD = full_query("SELECT T.id FROM tld T WHERE T.tld = '.". $tld . "' AND T.tld_registrar_id = (SELECT R.id FROM tld_registrar R WHERE R.name = 'DonDominio')");
		
		if(!is_array($checkTLD) || count($checkTLD) == 0){
			return false;
		}
		
		return true;
	}
	
	/**
	 * Create an order in the database.
	 * @param string $cname Domain name
	 * @return boolean|string
	 */
	protected function createOrder($cname, $expiration)
	{
		$uid = $this->options['clientId'];
		$dondominio = $this->options['apiClient'];
		
		list($sld, $tld) = explode('.', $cname);
		
		$config = array(
			'register_sld' => $sld,
			'register_tld' => '.' . $tld,
			'register_years' => 1,
			'ns1' => '',
			'ns2' => '',
			'ns3' => '',
			'ns4' => '',
			'transfer_sld' => '',
			'transfer_tld' => '.' . $tld,
			'transfer_code' => '',
			'action' => 'register',
			'multiple' => 1,
			'period' => '1Y',
			'quantity' => 1,
			'id' => 0,
			'product_id' => 1,
			'form_id' => null,
			'title' => 'Domain ' . $cname . ' imported',
			'type' => 'domain',
			'unit' => 'year',
			'price' => '0.00',
			'setup_price' => 0,
			'discount' => 0,
			'discount_price' => 0,
			'discount_setup' => 0,
			'total' => 0
		);
			
		$query = "
			INSERT INTO client_order
			(
				client_id,
				product_id,
				group_id,
				group_master,
				invoice_option,
				title,
				currency,
				service_id,
				service_type,
				period,
				quantity,
				unit,
				price,
				discount,
				status,
				config,
				expires_at,
				activated_at,
				created_at,
				updated_at
			) VALUES (
				'" . $uid . "',
				1,
				NULL,
				1,
				NULL,
				'Domain " . $cname . " imported',
				'USD',
				(SELECT id FROM service_domain WHERE sld = '" . $sld . "' AND tld = '." . $tld . "'),
				'domain',
				'1Y',
				1,
				'year',
				0,
				0,
				'active',
				'" . json_encode($config) . "',
				'" . $expiration . "',
				NOW(),
				NOW(),
				NOW()
			)
		";
		
		$order = full_query($query);
		
		if(!is_array($order)){
			return "Error creating order";
		}
		
		return true;
	}
	
	/**
	 * Updates a domain information in the database.
	 * @param array $domain Domain information
	 * @return boolean|string
	 */
	protected function updateDomain(array $domain = array())
	{
		$result = $this->getDomainInfo($domain['name']);
		
		if(!$result){
			return "Error updating domain";
		}
		
		list($info, $nameservers, $epp, $contacts) = $result;
		
		//SLD & TLD
		list($sld, $tld) = explode(".", $info->get("name"));
        
		/*
		 * Nameservers.
		 */
		$ns_array = $nameservers->get("nameservers");
		
		$ns1 = "";
		if(array_key_exists(0, $ns_array)){
				$ns1 = $ns_array[0]['name'];
		}
		
		$ns2 = "";
		if(array_key_exists(1, $ns_array)){
				$ns2 = $ns_array[1]['name'];
		}
		
		$ns3 = "";
		if(array_key_exists(2, $ns_array)){
				$ns3 = $ns_array[2]['name'];
		}
		
		$ns4 = "";
		if(array_key_exists(3, $ns_array)){
				$ns4 = $ns_array[3]['name'];
		}
		/* *** */
		
		//Authcode
		$eppCode = "";
		if($info->get("authcodeCheck")){
				$eppCode = $epp->get("authcode");
		}
		
		//Owner Contact
		$contact = $contacts->get("contactOwner");
		
		if(is_array($contact) && array_key_exists("phone", $contact)){
				list($phoneCc, $phone) = explode(".", $contact['phone']);
		}
		
		//Privacy & Lock
		$whoisPrivacy = ($info->get("whoisPrivacy")) ? '1' : '0';
		$transferBlock = ($info->get("transferBlock")) ? '1' : '0';
		
		$query = "
			UPDATE service_domain
			SET
				ns1 = '" . $ns1 . "',
				ns2 = '" . $ns2 . "',
				ns3 = '" . $ns3 . "',
				ns4 = '" . $ns4 . "',
				privacy = '" . $whoisPrivacy . "',
				locked = '" . $transferBlock . "',
				transfer_code = '" . $eppCode . "',
				contact_email = '" . $contact['email'] . "',
				contact_company = '" . $contact['orgName'] . "',
				contact_first_name = '" . $contact['firstName'] . "',
				contact_last_name = '" . $contact['lastName'] . "',
				contact_address1 = '" . $contact['address'] . "',
				contact_city = '" . $contact['city'] . "',
				contact_state = '" . $contact['state'] . "',
				contact_postcode = '" . $contact['postalCode'] . "',
				contact_country = '" . $contact['country'] . "',
				contact_phone_cc = '" . $phoneCc . "',
				contact_phone = '" . $phone . "',
				expires_at = '" . $info->get("tsExpir") . "'
			WHERE
				sld = '" . $sld . "'
				AND tld = '" . $tld . "'
		";
		
		$update = full_query($query);
		
		if(!is_array($update)){
			return "Error updating domain";
		}
		
		return true;
	}
	
	/**
	 * Create a domain.
	 * Returns true if the domain has been created, or the error from the database if failed.
	 * @param array $domain Domain information
	 * @return boolean|string
	 */
	protected function createDomain(array $domain = array())
	{
		$result = $this->getDomainInfo($domain['name']);
		
		if(!$result){
			return "Error creating domain";
		}
		
		list($info, $nameservers, $epp, $contacts) = $result;
		
		//SLD & TLD
		list($sld, $tld) = explode(".", $info->get("name"));
        
		/*
		 * Nameservers.
		 */
		$ns_array = $nameservers->get("nameservers");
		
		$ns1 = "";
		if(array_key_exists(0, $ns_array)){
				$ns1 = $ns_array[0]['name'];
		}
		
		$ns2 = "";
		if(array_key_exists(1, $ns_array)){
				$ns2 = $ns_array[1]['name'];
		}
		
		$ns3 = "";
		if(array_key_exists(2, $ns_array)){
				$ns3 = $ns_array[2]['name'];
		}
		
		$ns4 = "";
		if(array_key_exists(3, $ns_array)){
				$ns4 = $ns_array[3]['name'];
		}
		/* *** */
		
		//Authcode
		$eppCode = "";
		if($info->get("authcodeCheck")){
				$eppCode = $epp->get("authcode");
		}
		
		//Owner Contact
		$contact = $contacts->get("contactOwner");
		
		if(is_array($contact) && array_key_exists("phone", $contact)){
				list($phoneCc, $phone) = explode(".", $contact['phone']);
		}
		
		//Privacy & Lock
		$whoisPrivacy = ($info->get("whoisPrivacy")) ? '1' : '0';
		$transferBlock = ($info->get("transferBlock")) ? '1' : '0';
		
		//Creating domain
		$query = "
			INSERT INTO service_domain
			(
				client_id,
				tld_registrar_id,
				sld,
				tld,
				ns1,
				ns2,
				ns3,
				ns4,
				period,
				privacy,
				locked,
				transfer_code,
				contact_email,
				contact_company,
				contact_first_name,
				contact_last_name,
				contact_address1,
				contact_city,
				contact_state,
				contact_postcode,
				contact_country,
				contact_phone_cc,
				contact_phone,
				registered_at,
				expires_at,
				created_at,
				updated_at
			) VALUES (
				'" . $uid . "',
				(SELECT R.id FROM tld_registrar R WHERE R.name = 'DonDominio'),
				'" . $sld . "',
				'." . $tld . "',
				'" . $ns1 . "',
				'" . $ns2 . "',
				'" . $ns3 . "',
				'" . $ns4 . "',
				1,
				'" . $whoisPrivacy . "',
				'" . $transferBlock . "',
				'" . $eppCode . "',
				'" . $contact['email'] . "',
				'" . $contact['orgName'] . "',
				'" . $contact['firstName'] . "',
				'" . $contact['lastName'] . "',
				'" . $contact['address'] . "',
				'" . $contact['city'] . "',
				'" . $contact['state'] . "',
				'" . $contact['postalCode'] . "',
				'" . $contact['country'] . "',
				'" . $phoneCc . "',
				'" . $phone . "',
				'" . $info->get("tsCreate") . "',
				'" . $info->get("tsExpir") . "',
				NOW(),
				NOW()
			)
		";
					
		$create = full_query($query);
		
		if(!is_array($create)){
			return "Error creating domain";
		}
		
		//Creating order for customer
		$this->createOrder($domain['name'], $info->get("tsExpir"));
		
		return true;
	}
	
	/**
	 * Find the Client ID of a domain owner.
	 * Returns the Client ID of the owner if found, false if not found or the error from the database if failed.
	 * @param string $cname Domain
	 * @return integer|string|boolean
	 */
	protected function findDomainOwner($cname)
	{
		$dondominio = $this->options['apiClient'];
		
		$email = "";
		$ownerId = null;
		
		try{
			$info = $dondominio->domain_getInfo($cname, array('infoType' => 'contact'));
			
			$owner = $info->get("contactOwner");
			
			$email = $owner['email'];
		}catch(DonDominioAPI_Error $e){
			return false;
		}
		
		$owner = full_query("SELECT C.id FROM client C WHERE C.email = '" . $email . "'");		
		
		if(is_array($owner) && count($owner) > 0){
			$ownerId = $owner['C.id'];
		}
		
		return $ownerId;
	}
	
	/**
	 * Returns all domain information from API.
	 * @param string $cname Domain CNAME
	 * @return array
	 */
	protected function getDomainInfo($cname)
	{
		$uid = $this->options['clientId'];
		$dondominio = $this->options['apiClient'];
		
		try{
			$info = $dondominio->domain_getInfo(
				$cname,
				array(
					'infoType' => 'status'
				)
			);
			
			$nameservers = $dondominio->domain_getInfo(
				$cname,
				array(
					'infoType' => 'nameservers'
				)
			);
			
			$epp = null;
			
			if($info->get("authcodeCheck")){
				$epp = $dondominio->domain_getInfo(
					$cname,
					array(
						'infoType' => 'authcode'
					)
				);
			}
			
			$contacts = $dondominio->domain_getInfo(
				$cname,
				array(
					'infoType' => 'contact'
				)
			);
		}catch(DonDominioAPI_Error $e){
			Output::error("An error has occurred getting domain information: " . $e->getMessage());
			return false;
		}
		
		return array($info, $nameservers, $epp, $contacts);
	}
}