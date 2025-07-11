<?php

namespace Drupal\Core\Session;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

/**
 * Manages user sessions.
 *
 * This class implements the custom session management code inherited from
 * Drupal 7 on top of the corresponding Symfony component. Regrettably the name
 * NativeSessionStorage is not quite accurate. In fact the responsibility for
 * storing and retrieving session data has been extracted from it in Symfony 2.1
 * but the class name was not changed.
 *
 * @todo In fact the NativeSessionStorage class already implements all of the
 *   functionality required by a typical Symfony application. Normally it is not
 *   necessary to subclass it at all. In order to reach the point where Drupal
 *   can use the Symfony session management unmodified, the code implemented
 *   here needs to be extracted either into a dedicated session handler proxy
 *   (e.g. sid-hashing) or relocated to the authentication subsystem.
 */
class SessionManager extends NativeSessionStorage implements SessionManagerInterface {

  use DependencySerializationTrait;

  /**
   * Whether a lazy session has been started.
   *
   * @var bool
   */
  protected $startedLazy;

  /**
   * The write safe session handler.
   *
   * @var \Drupal\Core\Session\WriteSafeSessionHandlerInterface
   *
   * @todo This reference should be removed once all database queries
   *   are removed from the session manager class.
   */
  protected $writeSafeHandler;

  /**
   * Constructs a new session manager instance.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Session\MetadataBag $metadata_bag
   *   The session metadata bag.
   * @param \Drupal\Core\Session\SessionConfigurationInterface $sessionConfiguration
   *   The session configuration interface.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\HttpFoundation\Session\Storage\Proxy\AbstractProxy|\SessionHandlerInterface|null $handler
   *   The object to register as a PHP session handler.
   *
   * @see \Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage::setSaveHandler()
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected Connection $connection,
    MetadataBag $metadata_bag,
    protected SessionConfigurationInterface $sessionConfiguration,
    protected TimeInterface $time,
    $handler = NULL,
  ) {
    parent::__construct([], $handler, $metadata_bag);
  }

  /**
   * {@inheritdoc}
   */
  public function start(): bool {
    if (($this->started || $this->startedLazy) && !$this->closed) {
      return $this->started;
    }

    $request = $this->requestStack->getCurrentRequest();
    $this->setOptions($this->sessionConfiguration->getOptions($request));

    if ($this->sessionConfiguration->hasSession($request)) {
      // If a session cookie exists, initialize the session. Otherwise the
      // session is only started on demand in save(), making
      // anonymous users not use a session cookie unless something is stored in
      // $_SESSION. This allows HTTP proxies to cache anonymous page views.
      $result = $this->startNow();
    }

    if (empty($result)) {
      // Initialize the session global and attach the Symfony session bags.
      $_SESSION = [];
      $this->loadSession();

      // NativeSessionStorage::loadSession() sets started to TRUE, reset it to
      // FALSE here.
      $this->started = FALSE;
      $this->startedLazy = TRUE;

      $result = FALSE;
    }

    return $result;
  }

  /**
   * Forcibly start a PHP session.
   *
   * @return bool
   *   TRUE if the session is started.
   */
  protected function startNow() {
    if ($this->isCli()) {
      return FALSE;
    }

    if ($this->startedLazy) {
      // Save current session data before starting it, as PHP will destroy it.
      $session_data = $_SESSION;
    }

    $result = parent::start();

    // Restore session data.
    if ($this->startedLazy) {
      $_SESSION = $session_data;
      $this->loadSession();
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function save(): void {
    if ($this->isCli()) {
      // We don't have anything to do if we are not allowed to save the session.
      return;
    }

    if ($this->isSessionObsolete()) {
      // There is no session data to store, destroy the session if it was
      // previously started.
      if ($this->getSaveHandler()->isActive()) {
        $this->destroy();
      }
    }
    else {
      // There is session data to store. Start the session if it is not already
      // started.
      if (!$this->getSaveHandler()->isActive()) {
        $this->startNow();
      }
      // Write the session data.
      parent::save();
    }

    $allowedKeys = array_map(
      fn (SessionBagInterface $bag) => $bag->getStorageKey(),
      $this->bags
    );
    $allowedKeys[] = $this->getMetadataBag()->getStorageKey();
    $deprecatedKeys = array_diff(array_keys($_SESSION), $allowedKeys);
    if (count($deprecatedKeys) > 0) {
      @trigger_error(sprintf('Storing values directly in $_SESSION is deprecated in drupal:11.2.0 and will become unsupported in drupal:12.0.0. Use $request->getSession()->set() instead. Affected keys: %s. See https://www.drupal.org/node/3518527', implode(", ", $deprecatedKeys)), E_USER_DEPRECATED);
    }

    $this->startedLazy = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function regenerate($destroy = FALSE, $lifetime = NULL): bool {
    // Nothing to do if we are not allowed to change the session.
    if ($this->isCli()) {
      return FALSE;
    }

    // Drupal will always destroy the existing session when regenerating a
    // session. This is inline with the recommendations of @link https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html#renew-the-session-id-after-any-privilege-level-change
    // OWASP session management cheat sheet. @endlink
    $destroy = TRUE;

    // Cannot regenerate the session ID for non-active sessions.
    if (\PHP_SESSION_ACTIVE !== session_status()) {
      // Ensure the metadata bag has been stamped. If the parent::regenerate()
      // is called prior to the session being started it will not refresh the
      // metadata as expected.
      $this->getMetadataBag()->stampNew($lifetime);
      return FALSE;
    }

    return parent::regenerate($destroy, $lifetime);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($uid) {
    // Nothing to do if we are not allowed to change the session.
    if (!$this->writeSafeHandler->isSessionWritable() || $this->isCli()) {
      return;
    }
    // The sessions table may not have been created yet.
    try {
      $this->connection->delete('sessions')
        ->condition('uid', $uid)
        ->execute();
    }
    catch (\Exception) {
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
    if ($this->isCli()) {
      return;
    }

    // Symfony suggests using Session::invalidate() instead of session_destroy()
    // however the former calls session_regenerate_id(TRUE), which while
    // destroying the current session creates a new ID; Drupal has historically
    // decided to only set sessions when absolutely necessary (e.g., to increase
    // anonymous user cache hit rates) and as such we cannot use the Symfony
    // convenience method here.
    session_destroy();

    // Unset the session cookies.
    $session_name = $this->getName();
    $cookies = $this->requestStack->getCurrentRequest()->cookies;
    // setcookie() can only be called when headers are not yet sent.
    if ($cookies->has($session_name) && !headers_sent()) {
      $params = session_get_cookie_params();
      setcookie($session_name, '', $this->time->getRequestTime() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
      $cookies->remove($session_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setWriteSafeHandler(WriteSafeSessionHandlerInterface $handler) {
    $this->writeSafeHandler = $handler;
  }

  /**
   * Returns whether the current PHP process runs on CLI.
   *
   * Command line clients do not support cookies nor sessions.
   *
   * @return bool
   *   TRUE if the current PHP process runs on CLI, otherwise FALSE>
   */
  protected function isCli() {
    return PHP_SAPI === 'cli';
  }

  /**
   * Determines whether the session contains user data.
   *
   * @return bool
   *   TRUE when the session does not contain any values and therefore can be
   *   destroyed.
   */
  protected function isSessionObsolete() {
    $used_session_keys = array_filter($this->getSessionDataMask());
    return empty($used_session_keys);
  }

  /**
   * Returns a map specifying which session key is containing user data.
   *
   * @return array
   *   An array where keys correspond to the session keys and the values are
   *   booleans specifying whether the corresponding session key contains any
   *   user data.
   */
  protected function getSessionDataMask() {
    if (empty($_SESSION)) {
      return [];
    }

    // Start out with a completely filled mask.
    $mask = array_fill_keys(array_keys($_SESSION), TRUE);

    // Ignore the metadata bag, it does not contain any user data.
    $mask[$this->metadataBag->getStorageKey()] = FALSE;

    // Ignore attribute bags when they do not contain any data.
    foreach ($this->bags as $bag) {
      $key = $bag->getStorageKey();
      $mask[$key] = !empty($_SESSION[$key]);
    }

    return array_intersect_key($mask, $_SESSION);
  }

}
