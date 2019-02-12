<?php

namespace MauticPlugin\MauticCrmBundle\Api;

use Mautic\PluginBundle\Exception\ApiErrorException;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;

class InesCRMApi extends CrmApi
{
    //const ROOT_URL = 'https://webservices.inescrm.com'; // à conserver pour mémoire de l'url de base.

    const LOGIN_WS_PATH = '/wslogin/login.asmx';

    const CONTACT_MANAGER_WS_PATH = '/ws/wsicm.asmx';

    const CUSTOM_FIELD_WS_PATH = '/ws/wscf.asmx';

    const AUTOMATION_SYNC_WS_PATH = '/ws/WSAutomationSync.asmx';

    private $translator;

    private $syncInfo = null;

    private $cachedAuthHeader = null;

    private $loginClient = null;

    private $contactManagerClient = null;

    private $customFieldClient = null;

    private $automationSyncClient = null;

    private $notification;

    private $logger;

    public function __construct(CrmAbstractIntegration $integration)
    {
        parent::__construct($integration);
        $this->translator   = $integration->getTranslator();
        $this->notification = $integration->getNotificationModel();
        $this->logger       = $integration->getLogger();
    }

    public function getSyncInfo()
    {
        if (is_null($this->syncInfo)) {
            $client = $this->getAutomationSyncClient();
            //            error_log('get synch info request', 0, serialize($client->__getLastRequest()));
            //            error_log('get synch info requestheaders', 0, serialize($client->__getLastRequestHeaders()));

            $response = $client->GetSyncInfo();
            self::cleanList($response->GetSyncInfoResult->CompanyCustomFields->CustomFieldToAuto);
            self::cleanList($response->GetSyncInfoResult->ContactCustomFields->CustomFieldToAuto);

            $this->syncInfo = $response->GetSyncInfoResult;
        }

        return $this->syncInfo;
    }

    public function getClientCustomFields($internalRef)
    {
        $client = $this->getCustomFieldClient();
        try {
            $response = $client->GetCompanyCF(['reference' => $internalRef]);
        } catch (\Exception $e) {
            $this->addErrorInes($e, $client);

            return false;
        }

        self::cleanList($response->GetCompanyCFResult->Values->CustomField);
        self::cleanList($response->GetCompanyCFResult->Definitions->CustomFieldDefinition);
        self::cleanList($response->GetCompanyCFResult->Groups->CustomFieldGroup);

        return $response;
    }

    public function getContactCustomFields($internalRef)
    {
        $client = $this->getCustomFieldClient();
        try {
            $response = $client->GetContactCF(['reference' => $internalRef]);
        } catch (\Exception $e) {
            $this->addErrorInes($e, $client);

            return false;
        }
        self::cleanList($response->GetContactCFResult->Values->CustomField);
        self::cleanList($response->GetContactCFResult->Definitions->CustomFieldDefinition);
        self::cleanList($response->GetContactCFResult->Groups->CustomFieldGroup);

        return $response;
    }

    public function createClientCustomField($mappedData)
    {
        $client = $this->getCustomFieldClient();

        return $client->InsertCompanyCF($mappedData);
    }

    public function updateClientCustomField($mappedData)
    {
        $client = $this->getCustomFieldClient();

        return $client->UpdateCompanyCF($mappedData);
    }

    public function createContactCustomField($mappedData)
    {
        $client = $this->getCustomFieldClient();

        return $client->InsertContactCF($mappedData);
    }

    public function updateContactCustomField($mappedData)
    {
        $client = $this->getCustomFieldClient();

        return $client->UpdateContactCF($mappedData);
    }

    public function getClient($internalRef)
    {
        $client = $this->getContactManagerClient();
        //        error_log('get client request', 0, serialize($client->__getLastRequest()));
        //        error_log(serialize($client->__getLastRequest()));
        //        error_log('get client requestheaders', 0, serialize($client->__getLastRequestHeaders()));
        //        error_log(serialize($client->__getLastRequestHeaders()));

        return $client->GetClient(['reference' => $internalRef]);
    }

    public function getContact($internalRef)
    {
        $client = $this->getContactManagerClient();
        //        error_log('get contact request', 0, serialize($client->__getLastRequest()));
        //        error_log(serialize($client->__getLastRequest()));
        //        error_log('get contact requestheaders', 0, serialize($client->__getLastRequestHeaders()));
        //        error_log(serialize($client->__getLastRequestHeaders()));

        return $client->GetContact(['reference' => $internalRef]);
    }

    public function createClientWithContacts($mappedData)
    {
        $client = $this->getAutomationSyncClient();
        //        error_log('create client with contact request', 0, serialize($client->__getLastRequest()));
        //        error_log('create client with contact requestheaders', 0, serialize($client->__getLastRequestHeaders()));

        return $client->AddClientWithContacts($mappedData);
    }

    public function createClient($mappedData)
    {
        $client = $this->getAutomationSyncClient();
        //        error_log('create client request', 0, serialize($client->__getLastRequest()));
        //        error_log('create client requestheaders', 0, serialize($client->__getLastRequestHeaders()));

        return $client->AddClientWithContacts($mappedData);
    }

    public function createContact($mappedData)
    {
        $client = $this->getAutomationSyncClient();
        //        error_log('create contact request', 0, serialize($client->__getLastRequest()));
        //        error_log('create contact requestheaders', 0, serialize($client->__getLastRequestHeaders()));

        return $client->AddContact($mappedData);
    }

    public function createLead($mappedData)
    {
        $client = $this->getAutomationSyncClient();
        //        error_log('create lead request', 0, serialize($client->__getLastRequest()));
        //        error_log('create lead requestheaders', 0, serialize($client->__getLastRequestHeaders()));

        return $client->AddLead(['info' => $mappedData]);
    }

    public function updateClient($inesClient)
    {
        $client = $this->getAutomationSyncClient();
        try {
            $soapReturn = $client->UpdateClient(['client' => $inesClient]);
            //            error_log(serialize($client->__getLastRequest()));
            //            error_log(serialize($client->__getLastRequestHeaders()));
        } catch (Exception $e) {
            $this->addErrorInes($e, $client);

            return false;
        }

        return $soapReturn;
    }

    public function updateContact($inesContact)
    {
        $client = $this->getAutomationSyncClient();
        try {
            $soapReturn = $client->UpdateContact(['contact' => $inesContact]);
            //                    error_log(serialize($client->__getLastRequest()));
            //                    error_log(serialize($client->__getLastRequestHeaders()));
        } catch (Exception $e) {
            $this->addErrorInes($e, $client);

            return false;
        }

        return $soapReturn;
    }

    private function getLoginClient()
    {
        if (is_null($this->loginClient)) {
            $this->loginClient = self::makeClient(self::LOGIN_WS_PATH);
        }

        return $this->loginClient;
    }

    private function getContactManagerClient()
    {
        if (is_null($this->contactManagerClient)) {
            $this->contactManagerClient = self::makeClient(self::CONTACT_MANAGER_WS_PATH);
            $this->includeAuthHeader($this->contactManagerClient);
        }

        return $this->contactManagerClient;
    }

    private function getCustomFieldClient()
    {
        if (is_null($this->customFieldClient)) {
            $this->customFieldClient = self::makeClient(self::CUSTOM_FIELD_WS_PATH);
            $this->includeAuthHeader($this->customFieldClient);
        }

        return $this->customFieldClient;
    }

    private function getAutomationSyncClient()
    {
        if (is_null($this->automationSyncClient)) {
            $this->automationSyncClient = self::makeClient(self::AUTOMATION_SYNC_WS_PATH);
            $this->includeAuthHeader($this->automationSyncClient);
        }

        return $this->automationSyncClient;
    }

    private function makeClient($path)
    {
        $apiUrl = $this->integration->getApiUrl();

        return new \SoapClient($apiUrl.$path.'?wsdl', ['trace' => true]);
    }

    private function includeAuthHeader($client)
    {
        if (is_null($this->cachedAuthHeader)) {
            $sessionId              = $this->getSessionId();
            $this->cachedAuthHeader = new \SoapHeader('http://webservice.ines.fr', 'SessionID', ['ID' => $sessionId]);
        }

        $client->__setSoapHeaders($this->cachedAuthHeader);
    }

    private function getSessionId()
    {
        $keys   = $this->integration->getDecryptedApiKeys();
        $failed = false;

        try {
            $response = $this->getLoginClient()->authenticationWs([
              'request' => $keys,
            ]);

            if ($response->authenticationWsResult->codeReturn === 'failed') {
                $failed = true;
            }
        } catch (\SoapFault $e) {
            $failed = true;
        }

        if ($failed) {
            throw new ApiErrorException($this->translator->trans('mautic.ines_crm.form.invalid_identifiers'));
        }

        return $response->authenticationWsResult->idSession;
    }

    private static function cleanList(&$dirtyList)
    {
        if (!isset($dirtyList)) {
            $dirtyList = [];
        } elseif (!is_array($dirtyList)) {
            $dirtyList = [$dirtyList];
        }
    }

    private function addErrorInes(\Exception $e, $client)
    {
        $this->notification->addNotification(__METHOD__.'requête vers ines en erreur '.$e->getMessage());
        $this->logger->addError(__METHOD__.'requête vers ines en erreur '.$e->getMessage());

        $this->logger->addError('Last_request : '.$client->__getLastRequest());
        $this->logger->addError('Last_request_header : '.$client->__getLastRequestHeaders());

        mail('dco@webmecanik.com,r.benant@inescrm.com,f.boulanger@inescrm.com', 'Requête vers ines en erreur',
          'instance : '.__DIR__.'<br>requête vers INES :'.$client->__getLastRequest().'<br>header de la requête:'.$client->__getLastRequestHeaders().'<br>réponse de l API INES: '.$e->getMessage(), '', '-f infra@webmecanik.com');
    }
}
