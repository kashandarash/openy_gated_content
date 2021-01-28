<?php

namespace Drupal\openy_gc_auth_reclique_oauth2\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\openy_gc_auth_reclique_oauth2\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\openy_gc_auth\GCUserAuthorizer;

/**
 * Class with controller endpoints, needed for reclique_oauth2 plugin.
 */
class OAuth2Controller extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Config for openy_gated_content module.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  protected $configOpenyGatedContent;

  /**
   * The Gated Content User Authorizer.
   *
   * @var \Drupal\openy_gc_auth\GCUserAuthorizer
   */
  protected $gcUserAuthorizer;

  /**
   * Reclique OAuth2 client.
   *
   * @var \Drupal\openy_gc_auth_reclique_oauth2\Client
   */
  protected $recliqueOauth2Client;

  /**
   * Oauth2Controller constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory instance.
   * @param \Drupal\openy_gc_auth\GCUserAuthorizer $gcUserAuthorizer
   *   The Gated User Authorizer.
   * @param \Drupal\openy_gc_auth_reclique_oauth2\Client $recliqueOauth2Client
   *   Reclique OAuth2 Client.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    GCUserAuthorizer $gcUserAuthorizer,
    Client $recliqueOauth2Client
  ) {
    $this->configFactory = $configFactory;
    $this->configOpenyGatedContent = $configFactory->get('openy_gated_content.settings');
    $this->gcUserAuthorizer = $gcUserAuthorizer;
    $this->recliqueOauth2Client = $recliqueOauth2Client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('openy_gc_auth.user_authorizer'),
      $container->get('openy_gc_auth.reclique_oauth2_client')
    );
  }

  /**
   * Redirect, login user and return authorization code.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Current request object.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   Redirect to the Reclique login page.
   */
  public function authenticateRedirect(Request $request): TrustedRedirectResponse {
    if (!empty($this->response)) {
      return $this->response;
    }

    $oAuth2AuthenticateUrl = $this->recliqueOauth2Client->buildAuthenticationUrl($request);
    // dump($oAuth2AuthenticateUrl);.
    $this->response = new TrustedRedirectResponse($oAuth2AuthenticateUrl);
    $this->response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $this->response;
  }

  /**
   * Receive authorization code, load user data and authorize user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Current request object.
   *
   * @return mixed
   *   Returns RedirectResponse or JsonResponse.
   */
  public function authenticateCallback(Request $request) {
    // Check code that was generated by Open Y.
    if (!$this->recliqueOauth2Client->validateCsrfToken($request->get('state'))) {
      return new JsonResponse(
        [
          'error' => 1,
          'message' => 'Wrong cross site check',
        ]
      );
    }

    $access_token = $this->recliqueOauth2Client->exchangeCodeForAccessToken($request->get('code'));

    if (!$access_token) {
      return new JsonResponse(
        [
          'error' => 1,
          'message' => 'Failed to load access token',
        ]
      );
    }

    $userData = $this->recliqueOauth2Client->requestUserData($access_token);

    if (!$userData) {
      return new JsonResponse(
        [
          'error' => 1,
          'message' => 'Failed to load user data',
        ]
      );
    }

    // Return new JsonResponse(dump($userData));
    if ($this->recliqueOauth2Client->validateUserSubscription($userData)) {
      // @TODO implement $name, $email variables
      // $name = $userData->name->first_name . ' '
      // . $userDetails->name->last_name . ' ' . $userDetails->member_id;
      // $email = "reclique-{$userData->member_id}@virtualy.openy.org";
      $name = '';
      $email = '';

      // Authorize user (register, login, log, etc).
      $this->gcUserAuthorizer->authorizeUser($name, $email);

      return new RedirectResponse($this->configOpenyGatedContent->get('virtual_y_url'));
    }

    // Redirect back to Virual Y login page.
    return new RedirectResponse(
      URL::fromUserInput(
        $this->configOpenyGatedContent->get('virtual_y_login_url'),
        ['query' => ['error' => '1']]
      )->toString()
    );
  }

}
