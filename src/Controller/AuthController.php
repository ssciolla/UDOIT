<?php

namespace App\Controller;

use App\Entity\Institution;
use App\Services\BasicLtiService;
use App\Services\UtilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    /** @var UtilityService $util */
    private $util;
    /** @var SessionInterface $session */
    private $session;
    /** @var Request $request */
    private $request;
    /** @var BasicLtiService $ltiService */
    private $ltiService;

    private function prex($var) {
        print('<pre>' . print_r($var, true) . '</pre>');
        exit;
    }

    /**
     * @Route("/authorize", name="authorize")
     */
    public function authorize(
        Request $request,
        SessionInterface $session,
        UtilityService $util,
        BasicLtiService $ltiService)
    {
        $this->request = $request;
        $this->session = $session;
        $this->util = $util;
        $this->ltiService = $ltiService;

        // save .env values to session
        $env = $util->getEnv();
        $lmsId = $util->getLmsId();

        $user = $this->getUser();

        if (!$lmsId) {
            $util->exitWithMessage('Missing LMS ID.');
        }
        
        $this->saveRequestToSession();
        
        $institution = $this->util->getPreauthenticatedInstitution();
        if (!$institution) {
            $util->exitWithMessage('No institution found with this domain.');
        }

        if (UtilityService::ENV_DEV !== $env) {
            $this->verifyBasicLtiLaunch($institution);        
        }

        if (!empty($user) && $this->validateApiKey()) {
            return $this->redirectToRoute('dashboard');
        }

        $apiClientId = $institution->getApiClientId();
        $redirectUri = $this->getOauthRedirectUri();
        $scopes = $util->getLms()->getScopes();

        $oauthUri = "https://{$this->session->get('lms_api_domain')}/login/oauth2/auth/?client_id={$apiClientId}&scopes={$scopes}&response_type=code&redirect_uri={$redirectUri}";

        return $this->redirect($oauthUri);
    }

    /**
     * Previously called oauthresponse.php, this handles the reply from 
     * Canvas.
     * @Route("/authorize/check", name="authorize_check")
     */
    public function authorizeCheck(
        Request $request,
        SessionInterface $session,
        UtilityService $util,
        BasicLtiService $ltiService
    ) {
        $this->request = $request;
        $this->session = $session;
        $this->util = $util;
        $this->ltiService = $ltiService;

        if (!empty($request->query->get('error'))) {
            $util->exitWithMessage('Authentication problem: Access Denied. ' . $request->query->get('error'));
        }

        if (empty($request->query->get('code'))) {
            $util->exitWithMessage('Authentication problem: No code was returned from the LMS.');
        }

        $newKey = $this->authorizeNewApiKey();

        // It should have access_token and refresh_token
        if (!isset($newKey['access_token']) || !isset($newKey['refresh_token'])) {
            $util->exitWithMessage('Authentication problem: Missing access token. Please contact support.');
        }
        $this->updateUser($newKey);
        $this->session->set('apiKey', $newKey['access_token']);
        $this->session->set('tokenHeader', ["Authorization: Bearer " . $newKey['access_token']]);

        return $this->redirectToRoute('dashboard');
    }

    /**
     * Pass in the institution ID and this will encrypt the developer key.
     *
     * @route("/encrypt/key", name="encrypt_developer_key")
     */
    public function encryptDeveloperKey(Request $request, UtilityService $util)
    {
        $instId = $request->query->get('id');
        $institution = $util->getInstitutionById($instId);
        $institution->encryptDeveloperKey();
        $this->getDoctrine()->getManager()->flush();

        return new Response('Updated.');
    }

    private function validateApiKey()
    {
        $user = $this->getUser();
        $apiKey = $user->getApiKey();

        if (empty($apiKey)) {
            return false;
        }

        $tokenHeader = ["Authorization: Bearer " . $apiKey];
        $this->session->set('tokenHeader', $tokenHeader);
        
        try {
            $lms = $this->util->getLms();
            $profile = $lms->testApiConnection();

            if (empty($profile)) {
                throw new \Exception('Access token is invalid or expired.');
            }

            return true;
        }
        catch (\Exception $e) {
            $refreshed = $this->refreshApiKey();
            if (!$refreshed) {
                return false;
            }

            $profile = $lms->testApiConnection();

            if (!$profile) {
                return false;
            }

            return true;
        }    
    }

    private function refreshApiKey()
    {
        $user = $this->getUser();
        $refreshToken = $user->getRefreshToken();
        $institution = $user->getInstitution();
        $baseUrl = $this->session->get('lms_api_domain');

        if (empty($refreshToken)) {
            return false;
        }

        $options = [
            'query' => [
                'grant_type'    => 'refresh_token',
                'client_id'     => $institution->getApiClientId(),
                'redirect_uri'  => $this->getOauthRedirectUri(),
                'client_secret' => $institution->getApiClientSecret(),
                'refresh_token' => $refreshToken,
            ],
            'verify_host' => false,
            'verify_peer' => false,
        ];

        $client = HttpClient::create();
        $requestUrl = "https://{$baseUrl}/login/oauth2/token";
        $response = $client->request('POST', $requestUrl, $options);
        $contentStr = $response->getContent(false);
        $newKey = \json_decode($contentStr, true);

        // update the token in the database
        if (isset($newKey['access_token'])) {
            $this->updateUser($newKey);

            return true;
        }
        else {
            return false;
        }
    }

    private function verifyBasicLtiLaunch(Institution $institution)
    {
        $consumerKey = $institution->getConsumerKey();
        $sharedSecret = $institution->getSharedSecret();

        if (!$this->ltiService->isValid($consumerKey, $sharedSecret)) {
            $this->util->exitWithMessage($this->ltiService->getMessage());
        }

        return true;
    }

    private function saveRequestToSession()
    {
        try {
            $postParams = $this->request->request->all();

            foreach ($postParams as $key => $val) {
                $this->session->set($key, $val);
            }
        } catch (\Exception $e) {
            print_r($e->getMessage());
        }

        return;
    }

    private function getOauthRedirectUri()
    {
        return $this->request->server->get('APP_OAUTH_REDIRECT_URL');
    }

    private function updateUser($apiKey)
    {
        $user = $this->util->getPreauthenticatedUser();
        $user->setApiKey($apiKey['access_token']);

        if (isset($apiKey['refresh_token'])) {
            $user->setRefreshToken($apiKey['refresh_token']);
        }

        $now = new \DateTime();
        $user->setLastLogin($now);

        $this->getDoctrine()->getManager()->merge($user);
        $this->getDoctrine()->getManager()->flush();
    }

    private function authorizeNewApiKey()
    {
        $institution = $this->util->getPreauthenticatedInstitution();
        $baseUrl = $this->session->get('lms_api_domain');
        $code = $this->request->query->get('code');
        $options = [
            'query' => [
                'grant_type'    => 'authorization_code',
                'client_id'     => $institution->getApiClientId(),
                'redirect_uri'  => $this->getOauthRedirectUri(),
                'client_secret' => $institution->getApiClientSecret(),
                'code'          => $code,
            ],
            'verify_host' => false,
            'verify_peer' => false,
        ];

        $client = HttpClient::create();
        $requestUrl = "https://{$baseUrl}/login/oauth2/token";
        $response = $client->request('POST', $requestUrl, $options);
        $contentStr = $response->getContent(false);

        return \json_decode($contentStr, true);
    }
}
