<?php
/**
 * @copyright   2016 Webmecanik
 * @author      Webmecanik
 * @link        http://www.webmecanik.com
 */

namespace MauticPlugin\MauticCrmBundle\Integration;

use Mautic\LeadBundle\Entity\Lead;

/**
 * Class InesIntegration
 */
class InesIntegration extends CrmAbstractIntegration
{

	/**
	 * Array	Mapping retourné par l'intégration, mémorisé en attribut pour limiter les appels
	 */
	protected $mapping = false;


    /**
     * Nom de l'intégration (doit correspondre au nom du fichier et de la classe)
     *
     * @return string
     */
    public function getName()
    {
        return 'Ines';
    }


	/**
	 * Message affiché en tête de l'onglet de config
	 *
	 * @return string
	 */
	public function getDescription()
	{
		$message = $this->getTranslator()->trans('mautic.ines.description');
		return $message;
	}


	/**
	 * L'intégration supporte la fonction "push lead to integration"
	 *
	 * return 	array(string)
	 */
	public function getSupportedFeatures()
    {
        return array('push_lead');
    }


	/**
	 * Liste des champs nécessaires à la configuration du compte INES, pour accéder aux Web-Services
	 * Les champs dans le formulaire de config, leur hydratation et sauvegarde sont gérés automatiquement
	 *
	 * @return array
	 */
	public function getRequiredKeyFields()
	{
		return array(
			'compte' => 'mautic.ines.form.account',
			'userName' => 'mautic.ines.form.user',
			'password' => 'mautic.ines.form.password'
		);
	}


	/**
	 * Indique quels champs de la config du plugin doivent être en INPUT de type PASSWORD
	 *
	 * @return array
	 */
	public function getSecretKeys()
    {
        return [
            'password'
        ];
    }


	/**
     * Ajoute des champs dans les formulaires "config" et "features" du plugin
	 *
	 * @param \Mautic\PluginBundle\Integration\Form|FormBuilder $builder
     * @param array                                             $data
     * @param string                                            $formArea
     */
    public function appendToForm(&$builder, $data, $formArea)
    {
		// Tant que l'utilisateur navigue entre les onglets de config, il est préférable de ne pas conserver
		// la config en provenance du WS INES : si elle est modifiée chez INES on doit le savoir tout de suite.
		// Par exemple l'ajout d'un custom field.
		$this->unsetCurrentSyncConfig();

		// Onglet Enabled/Auth
		if ($formArea == 'keys') {

			// Ajout d'un bouton pour tester la connexion
			$builder->add('check_api_button', 'standalone_button', array(
				'label' => 'mautic.ines.form.check.btn',
				'attr'     => array(
                    'class'   => 'btn btn-primary',
                    'onclick' => "
						var btn = mQuery(this);
						btn.next('.message').remove();
						Mautic.postForm(mQuery('form[name=\"integration_details\"]'), function (response) {
							if (response.newContent) {
						        Mautic.processModalContent(response, '#IntegrationEditModal');
						    } else {
								mQuery.ajax({
							        url: mauticAjaxUrl,
							        type: 'POST',
							        data: 'action=plugin:mauticCrm:inesCheckConnexion',
							        dataType: 'json',
							        success: function (response) {
										btn.after('<span class=\"message\" style=\"font-weight:bold; margin-left:10px;\">' + response.message + '</span>');
							        },
							        error: Mautic.processAjaxError,
							        complete: Mautic.stopIconSpinPostEvent
							    });
							}
						});",
					'icon' => 'fa fa-check',
                ),
				'required' => false
			));
		}

		// Onglet Features
		else if ($formArea == 'features') {

			// Case à cocher : synchro complète ?
			$builder->add(
                'full_sync',
                'choice',
                [
                    'choices'     => [
                        'is_full_sync' => 'mautic.ines.isfullsync'
                    ],
                    'expanded'    => true,
                    'multiple'    => true,
                    'label'       => 'mautic.ines.form.isfullsync',
                    'label_attr'  => ['class' => 'control-label'],
                    'empty_value' => false,
                    'required'    => false
                ]
            );

			// Bouton : afficher le journal de bord
			$logsUrl = $this->factory->getRouter()->generate('ines_logs');
			$builder->add('goto_logs_button', 'standalone_button', array(
				'label' => 'mautic.ines.form.gotologs.btn',
				'attr'     => array(
                    'class'   => 'btn',
                    'onclick' => "window.open('$logsUrl')",
                ),
				'required' => false
			));


			// Liste des champs disponibles chez INES et disponibles pour l'option "champ écrasable"
			try {
				if ($this->isAuthorized()) {
					$inesFields = $this->getApiHelper()->getLeadFields();
					$choices = array();
					foreach($inesFields as $field) {
						if ($field['excludeFromEcrasableConfig']) {
							continue;
						}
						$key = $field['concept'].'_'.$field['inesKey'];
						$choices[$key] = $field['inesLabel'];
					}

					$builder->add(
						'not_ecrasable_fields',
						'choice',
						array(
							'choices'     => $choices,
							'expanded'    => true,
							'multiple'    => true,
							'label'       => 'mautic.ines.form.protected.fields',
							'label_attr'  => ['class' => ''],
							'empty_value' => false,
							'required'    => false
						)
					);
				}
			}
			catch (\Exception $e) {}
		}
	}


	/**
	 * Vérifie s'il existe un ID de session INES, ou, dans le cas contraire, si les codes d'accès aux WS sont valides
	 *
	 * @return bool
	 */
	public function isAuthorized()
	{
		if (!$this->isConfigured()) {
			return false;
		}

		$sessionID = $this->getWebServiceCurrentSessionID();
		return $sessionID ? true : $this->checkAuth();
	}


	/**
	 * Indique si le mode synchro complète est coché ou non dans la config du plugin
	 *
	 * @return bool
	 */
	public function isFullSync()
	{
		$settings = $this->getIntegrationSettings();

		// Si l'intégration est désactivée, on ne doit rien synchroniser
		if ( $settings->getIsPublished() === false) {
			return false;
		}

		$featureSettings = $settings->getFeatureSettings();
		$isFullSync = (isset($featureSettings['full_sync']) && count($featureSettings['full_sync']) === 1);
		return $isFullSync;
	}


	/**
	 * Vérifie si les codes d'accès aux web-services sont valides
	 * La session INES est supprimée au préalable si elle existe
	 *
	 * @return bool
	 */
	public function checkAuth()
	{
		try {
			$this->getApiHelper()->refreshSessionID();
			return true;

		} catch (\Exception $e) {
			$this->logIntegrationError($e);
			return false;
		}
	}


	/**
	 * Respectivement : lit, écrit et supprime l'ID de session temporaire nécessaire aux appels aux web-services INES
	 */
	public function getWebServiceCurrentSessionID()
	{
		return $this->factory->getSession()->get('ines_session_id');
	}
	public function setWebServiceCurrentSessionID($sessionID)
	{
		$this->factory->getSession()->set('ines_session_id', $sessionID);
	}
	public function unsetWebServiceCurrentSessionID()
	{
		$this->factory->getSession()->set('ines_session_id', null);
	}


	/**
	 * Respectivement : lit, écrit et supprime en session PHP la config de la synchro définie chez INES
	 */
	public function getCurrentSyncConfig()
	{
		return $this->factory->getSession()->get('ines_sync_config');
	}
	public function setCurrentSyncConfig($syncConfig)
	{
		$this->factory->getSession()->set('ines_sync_config', $syncConfig);
	}
	public function unsetCurrentSyncConfig()
	{
		$this->factory->getSession()->set('ines_sync_config', null);
	}


	/**
	 * Prépare le formulaire de mapping des champs
	 *
	 * @return 	array
	 * @throws 	\Exception
	 */
	public function getAvailableLeadFields ($settings = array())
	{
		$inesFields = array();
		$silenceExceptions = (isset($settings['silence_exceptions'])) ? $settings['silence_exceptions'] : true;

		try {
            if ($this->isAuthorized()) {
                $leadFields = $this->getApiHelper()->getLeadFields();

				foreach($leadFields as $field) {

					// Les champs dont le mapping est imposé en interne sont exclus du formulaire de mapping
					if ($field['autoMapping'] !== false) {
						continue;
					}

					$key = $field['concept'].'_'.$field['inesKey'];

					$inesFields[$key] = array(
						'type' => 'string',
						'label' => $field['inesLabel'],
						'required' => $field['isMappingRequired']
					);
				}
			}
		} catch (\Exception $e) {
			$this->logIntegrationError($e);

			if (!$silenceExceptions) {
				throw $e;
			}
		}

		// Ajout du champ de sélection du champ "ne pas synchroniser avec INES"
		$inesFields = array_merge(
			array_slice($inesFields, 0, 2),
			array(
				'dontSyncToInes' => array(
					'type' => 'string',
					'label' => 'Indicateur : ne pas synchroniser',
					'required' => true
				)
			),
			array_slice($inesFields, 2)
		);

		return $inesFields;
	}


	/**
	 * Retourne le mapping brut, issu du formulaire de mapping, sous forme d'un tableau associatif : ines_key => atmt_key
	 * Ne prend pas en compte les champs mappé automatiquement
	 *
	 * @return 	array
	 */
	public function getRawMapping()
	{
		if (!$this->isConfigured()) {
			return array();
		}

		$featureSettings = $this->getIntegrationSettings()->getFeatureSettings();
		$rawMapping = $featureSettings['leadFields'];

		// Retrait du flag "ne pas synchroniser avec INES", qui ne doit pas être mappé
		foreach($rawMapping as $internalKey => $atmtKey) {
			if ($internalKey == 'dontSyncToInes') {
				unset($rawMapping[$internalKey]);
			}
		}

		return $rawMapping;
	}


	/**
	 * Retourne l'identifiant du champ Automation contenant le flag "ne pas synchroniser"
	 *
	 * @return string
	 */
	public function getDontSyncAtmtKey()
	{
		if (!$this->isConfigured()) {
			return '';
		}

		// Recherche du champ contenant l'info "don't sync"
		$featureSettings = $this->getIntegrationSettings()->getFeatureSettings();
		$rawMapping = $featureSettings['leadFields'];
		foreach($rawMapping as $internalKey => $atmtKey) {
			if ($internalKey == 'dontSyncToInes') {
				return $atmtKey;
			}
		}

		return '';
	}


	/**
	 * Vérifie si un contact a le flag "ne pas synchroniser" levé
	 *
	 * @param 	Mautic\LeadBundle\Entity\Lead	$lead
	 * @return 	bool
	 */
	public function getDontSyncFlag(Lead $lead)
	{
		// Clé du champ don't sync
		$dontSyncAtmtKey = $this->getDontSyncAtmtKey();

		// Parcours des champs du contact, à la recherche du champ "don't sync"
		$fields = $lead->getProfileFields();
		foreach($fields as $key => $value) {
			if ($key == $dontSyncAtmtKey) {
				return (bool)$value;
			}
		}

		// Si le champ n'est pas trouvé, par défaut on refuse la synchro
		return true;
	}


	/**
	 * Retourne la liste des champs non écrasables
	 *
	 * @param 	string 	$filterByConcept	contact | client
	 * @return 	array
	 */
	public function getNotEcrasableFields($filterByConcept = false)
	{
		$featureSettings = $this->getIntegrationSettings()->getFeatureSettings();
		$fields = isset($featureSettings['not_ecrasable_fields']) ? $featureSettings['not_ecrasable_fields'] : array();

		// Retour brut, sous la forme concept_fieldKey
		if ($filterByConcept === false) {
			return $fields;
		}

		// Retour filtré et sans préfixe
		$conceptLength = strlen($filterByConcept);
		foreach($fields as $f => $field) {
			if (substr($field, 0, $conceptLength) == $filterByConcept) {
				$fields[$f] = substr($field, $conceptLength + 1);
			}
		}
		return $fields;
	}


	/**
	 * Retourne le mapping complet, avec tous les détails connus sur chaque champ
	 *
	 * @return 	array
	 */
	public function getMapping()
	{
		// Si déjà généré dans le même runtime, le retourne immédiatement
		if ($this->mapping !== false) {
			return $this->mapping;
		}

		$mappedFields = array();

		// Liste de tous les champs INES disponibles
		$leadFields = $this->getApiHelper()->getLeadFields();

		// Liste des champs non écrasables ? (sous la forme : concept_inesKey)
		// 1 : ceux cochés dans le formulaire par l'utilisateur
		$notEcrasableFields = $this->getNotEcrasableFields();
		// 2 : les champs exclus du formulaire
		foreach($leadFields as $field) {
			if ($field['excludeFromEcrasableConfig']) {
				$notEcrasableFields[] = $field['concept'].'_'.$field['inesKey'];
			}
		}

		// Liste des champs custom
		$customFields = array();
		foreach($leadFields as $field) {
			if ($field['isCustomField']) {
				$customFields[] = $field['concept'].'_'.$field['inesKey'];
			}
		}

		// Lecture et enrichissement des champs auto-mappés
		foreach($leadFields as $field) {

			if ($field['autoMapping'] !== false) {

				$internalKey = $field['concept'].'_'.$field['inesKey'];

				$mappedFields[] = array(
					'concept' => $field['concept'],
					'inesFieldKey' => $field['inesKey'],
					'isCustomField' => $field['isCustomField'] ? 1 : 0,
					'atmtFieldKey' => $field['autoMapping'],
					'isEcrasable' => in_array($internalKey, $notEcrasableFields) ? 0 : 1
				);
			}
		}

		// Lecture et enrichissement des champs mappés dans le formulaire, par l'utilisateur
		$rawMapping = $this->getRawMapping();
		foreach($rawMapping as $internalKey => $atmtKey) {

			list($concept, $inesKey) = explode('_', $internalKey);

			$mappedFields[] = array(
				'concept' => $concept,
				'inesFieldKey' => $inesKey,
				'isCustomField' => in_array($internalKey, $customFields) ? 1 : 0,
				'atmtFieldKey' => $atmtKey,
				'isEcrasable' => in_array($internalKey, $notEcrasableFields) ? 0 : 1
			);
		}

		// Mémorisation en cas d'appel dans la même runtime
		$this->mapping = $mappedFields;

		return $mappedFields;
	}


	/**
	 * Retourne le mapping automatique des champs de base ATMT / Company avec les champs INES / Client équivalents
	 *
	 * @return 	array
	 */
	public function getCompanyAutoMapping()
	{
		// clé INES/client => clé ATMT/company
		return array(
			'AutomationRef' => 'id',
			'PrimaryMailAddress' => 'companyemail',
			'Address1' => 'companyaddress1',
			'Address2' => 'companyaddress2',
			'ZipCode' => 'companyzipcode',
			'City' => 'companycity',
			'State' => 'companystate',
			'Country' => 'companycountry',
			'Phone' => 'companyphone',
			'Website' => 'companywebsite'
		);
	}


	/**
	 * Mémorise les clés INES de contact et de société (=client) dans les champs d'un lead (définis par le mapping)
	 *
	 * @param 	Mautic\LeadBundle\Entity\Lead	$lead
	 * @param 	int 							$internalCompanyRef 	Clé INES d'une société
	 * @param 	int 							$internalContactRef 	Clé INES d'un contact
	 *
	 * @return 	Mautic\LeadBundle\Entity\Lead
	 */
	public function setInesKeysToLead($lead, $internalCompanyRef, $internalContactRef)
	{
		$fieldsToUpdate = array();

		// Recherche des champs ATMT choisis pour mémoriser ces clés
		$mapping = $this->getMapping();
		foreach($mapping as $mappingItem) {

			if ($mappingItem['inesFieldKey'] == 'InternalContactRef') {
				$fieldsToUpdate[ $mappingItem['atmtFieldKey'] ] = $internalContactRef;
			}
			if ($mappingItem['inesFieldKey'] == 'InternalCompanyRef') {
				$fieldsToUpdate[ $mappingItem['atmtFieldKey'] ] = $internalCompanyRef;
			}
		}

		// Enregistrement des champs du lead
		$model = $this->factory->getModel('lead.lead');
		$model->setFieldValues($lead, $fieldsToUpdate, true);
		$model->saveEntity($lead);

		return $lead;
	}


	/**
	 * Requêtes SOAP d'un webservice
	 * Si la requête doit contenir un entête, le spécifier dans $settings['soapHeader']
	 *
	 * @param 	string 	$url 			URL complète de la requête SOAP
	 * @param 	array 	$parameters 	Paramètres à transmettre à la méthode $method
	 * @param 	string 	$method 		Méthode à appeler sur l'objet SOAP
	 * @param 	array 	$settings 		Configuration de la requête
	 *
	 * @return 	Object (réponse de l'API)
	 */
	public function makeRequest($url, $parameters = array(), $method = '', $settings = array())
	{
		$client = new \SoapClient($url);

		// Header SOAP, si demandé
		$soapHeader = isset($settings['soapHeader']) ? $settings['soapHeader'] : false;
		if ($soapHeader !== false) {
			$client->__setSoapHeaders(
				new \SoapHeader($soapHeader['namespace'], $soapHeader['name'], $soapHeader['datas'])
			);
		}

		// Appel d'une méthode du web-service, avec ou sans paramètres
		if ( !empty($parameters)) {
			$response = $client->$method($parameters);
		}
		else {
			$response = $client->$method();
		}

		return $response;
	}


	/**
	 * Ajout d'un lead à la file d'attente des leads à synchroniser, s'il n'y est pas déjà
	 *
	 * @param 	Mautic\LeadBundle\Entity\Lead	$lead
	 * @param 	string 							$action 	'UPDATE' | 'DELETE'
	 *
	 * @return 	bool
	 */
	public function enqueueLead(Lead $lead, $action = 'UPDATE')
	{
		$leadId = $lead->getId();
		$company = $this->getLeadMainCompany($leadId);
		$dontSyncToInes = $this->getDontSyncFlag($lead);

		// Le lead ne doit pas être anonyme
		if ( !empty($lead->getEmail()) && !empty($company) && !$dontSyncToInes) {

			// L'intégration doit être en mode 'full sync'
			if ($this->isFullSync()) {

				// Si le lead existe déjà en file d'attente, on le supprime
				// Permet d'éviter les mises à jour multiple.
				// Et considère la dernière action comme prioritaire sur les autres.
				$this->dequeuePendingLead($lead->getId());

				// Ajout d'une entrée dans la table "ines_sync_log"

				$inesSyncLogModel = $this->factory->getModel('crm.ines_sync_log');
				$entity = $inesSyncLogModel->getEntity();

				$company = $this->getLeadMainCompany($lead->getId());

				$entity->setAction($action)
					   ->setLeadId( $lead->getId() )
					   ->setLeadEmail( $lead->getEmail() )
					   ->setLeadCompany($company);

				$inesSyncLogModel->saveEntity($entity);

				return true;
			}
		}
		return false;
	}


	/**
	 * Retire un lead de la file d'attente des leads à synchroniser
	 *
	 * @param 	int		$leadId
	 */
	public function dequeuePendingLead($leadId)
	{
		$inesSyncLogModel = $this->factory->getModel('crm.ines_sync_log');
		$inesSyncLogModel->removeEntitiesBy(
			array(
				'leadId' => $leadId,
				'status' => 'PENDING'
			)
		);
	}


	/**
	 * Ajoute dans la file d'attente un lot de leads qui n'ont jamais été synchronisés,
	 * à condition que la file d'attente soit vide.
	 * Permet de gérer automatiquement et progressivement la 1ère synchro lors de la mise en service du mode full-sync
	 *
	 * @param 	$limit 	Nombre maximum de leads à ajouter à la file d'attente
	 *
	 * @return 	$enqueuedCounter 	Nombre de leads ajoutés à la file d'attente
	 */
	public function firstSyncCheckAndEnqueue($limit = 100)
	{
		$inesSyncLogModel = $this->factory->getModel('crm.ines_sync_log');
		$leadModel = $this->factory->getModel('lead.lead');

		// Si la file d'attente n'est pas vide, on ne fait rien
		if ( !$inesSyncLogModel->havePendingEntities('UPDATE')) {
			return 0;
		}

		// Recherche des clés ATMT contenant les clés INES de contact et client
		$atmtFieldsKeys = $this->getApiHelper()->getAtmtFieldsKeysFromInesFieldsKeys(
			['InternalContactRef', 'InternalCompanyRef']
		);
		if (isset($atmtFieldsKeys['InternalContactRef']) && isset($atmtFieldsKeys['InternalCompanyRef'])) {
			$inesContactAtmtKey = $atmtFieldsKeys['InternalContactRef'];
			$inesClientAtmtKey = $atmtFieldsKeys['InternalCompanyRef'];
		}
		else {
			return 0;
		}

		// Recherche des leads ayant une société ET un email ET les clés INES non renseignées
		$items = $this->factory->getEntityManager()
			 ->getConnection()
			 ->createQueryBuilder()
			 ->select('DISTINCT(l.id)')
			 ->from(MAUTIC_TABLE_PREFIX.'companies_leads', 'cl')
			 ->innerJoin('cl', MAUTIC_TABLE_PREFIX.'leads', 'l', 'l.id = cl.lead_id')
			 ->where(
			 	'l.email <> "" AND ('.
				 	'l.'.$inesContactAtmtKey.' IS NULL OR '.
					'l.'.$inesContactAtmtKey.' <= 0 OR '.
					'l.'.$inesContactAtmtKey.' LIKE "" OR '.
					'l.'.$inesClientAtmtKey.' IS NULL OR '.
					'l.'.$inesClientAtmtKey.' <= 0 OR '.
					'l.'.$inesClientAtmtKey.' LIKE "" '.
				')'
			 )
			 ->setFirstResult(0)
			 ->setMaxResults($limit)
			 ->execute()
			 ->fetchAll();

		// Ajout des leads trouvés à la file d'attente
		$enqueuedCounter = 0;
		if ($items) {
			foreach($items as $item) {
				$leadId = $item['id'];
				$lead = $leadModel->getEntity($leadId);
				if ($this->enqueueLead($lead)) {
					$enqueuedCounter++;
				}
			}
		}

		return $enqueuedCounter;
	}


	/**
	 * Synchronise un lot de leads présents en file d'attente
	 *
	 * @param 	int 	$numberToProcess
	 */
	public function syncPendingLeadsToInes($numberToProcess)
	{
		$updatedCounter = 0;
		$failedUpdatedCounter = 0;
		$deletedCounter = 0;
		$failedDeletedCounter = 0;
		$apiHelper = $this->getApiHelper();
		$leadModel = $this->factory->getModel('lead.lead');

		// ETAPE 1 : UPDATE lot de leads à SYNCHRONISER
		$inesSyncLogModel = $this->factory->getModel('crm.ines_sync_log');
		$pendingItems = $inesSyncLogModel->getPendingEntities('UPDATE', $numberToProcess);

		foreach($pendingItems as $item) {

			// Lead courant ?
			$leadId = $item->getLeadId();
			$lead = $leadModel->getEntity($leadId);

 			// S'il est trouvé, synchronisation
			if ($lead && $lead->getId() == $leadId) {

				$syncOk = $apiHelper->syncLeadToInes($lead);

				$itemCounter = $item->getCounter();

				// Synchronisation OK
				if ($syncOk) {
					$updatedCounter++;
					$itemStatus = 'DONE';
					$itemCounter++;

					// Dans le cas de la synchro d'un nouveau lead, l'écriture des clés INES contact et client dans le lead
					// déclenchent un ajout intempestif du lead à la file d'attente. D'où ce nettoyage :
					$this->dequeuePendingLead($lead->getId());
				}
				// Synchronisation ECHOUÉE
				else {
					$failedUpdatedCounter++;
					$itemCounter++;
					if ($itemCounter == 3) {
						$itemStatus = 'FAILED';
					}
				}

				// Mise à jour de l'enregistrement en DB
				$item->setCounter($itemCounter);
				$item->setStatus($itemStatus);
				$inesSyncLogModel->saveEntity($item);
			}
			// S'il n'est pas trouvé : status FAILED
			else {
				$item->setStatus('FAILED');
				$inesSyncLogModel->saveEntity($item);
			}
		}

		// ETAPE 2 : DELETE : lot de leads à SUPPRIMER
		$pendingDeletingItems = $inesSyncLogModel->getPendingEntities('DELETE', $numberToProcess);
		$failedDeletedCounter = count($pendingDeletingItems);
		/* TODO */

		return array($updatedCounter, $failedUpdatedCounter, $deletedCounter, $failedDeletedCounter);
	}


	/**
	 * Retourne le nom de la société principale (= 1ère de la liste) liée à un contact.
	 * Ou une chaîne vide si n'existe pas.
	 * Si $onlyName vaut false, retourne l'ensemble des champs de cette companie, ou false.
	 *
	 * @param 	int 	$leadId
	 * @param 	bool 	$onlyName
	 *
	 * @return 	mixed : string | array | false
	 */
	public function getLeadMainCompany($leadId, $onlyName = true)
	{
		$companyRepo = $this->factory->getModel('lead.company')->getRepository();
		$companies = $companyRepo->getCompaniesByLeadId($leadId);

		if ($onlyName) {
			return isset($companies[0]) ? $companies[0]['companyname'] : '';
		}
		else {
			if ( !isset($companies[0]['id'])) {
				return false;
			}

			$company_id = $companies[0]['id'];
			$company = $companyRepo->getEntity($company_id);
			return $company->getProfileFields();
		}
	}


	/**
	 * Pour le DEBUG : écrit une ligne dans le log de Mautic
	 *
	 * @param 	Object 	$object
	 */
	public function log($object)
	{
		$this->factory->getLogger()->log('info', var_export($object, true));
	}
}
