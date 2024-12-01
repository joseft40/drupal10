<?php
namespace Drupal\redirect_site_lang\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManager;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class LanguageRedirectMiddleware implements HttpKernelInterface {

  protected HttpKernelInterface $httpKernel;
  protected LanguageManagerInterface $languageManager;
  protected CurrentPathStack $currentPath;
  protected AliasManager $aliasManager;
  protected EntityRepositoryInterface $entityRepository;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    HttpKernelInterface $http_kernel,
    LanguageManagerInterface $language_manager,
    CurrentPathStack $current_path,
    AliasManager $alias_manager,
    EntityRepositoryInterface $entity_repository,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->httpKernel = $http_kernel;
    $this->languageManager = $language_manager;
    $this->currentPath = $current_path;
    $this->aliasManager = $alias_manager;
    $this->entityRepository = $entity_repository;
    $this->entityTypeManager = $entity_type_manager;
  }

  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response {
    $current_path = $this->currentPath->getPath();
    $internal_path = $this->aliasManager->getPathByAlias(
      substr($current_path, 3), 
      $this->languageManager->getCurrentLanguage()->getId()
    );
  
    $path_segments = explode('/', trim($current_path, '/'));
    $enabled_languages = $this->languageManager->getLanguages();
    $current_prefix = $path_segments[0] ?? '';
    $preferred_language = $request->getPreferredLanguage(array_keys($enabled_languages));
  
    // Obtén el valor de la cookie.
    $cookie_value = $request->cookies->get('redirect_site_lang', null);
  
    // Redirección basada en el idioma preferido y la cookie.
    if (empty($cookie_value)) {
      if ($current_prefix != $preferred_language && isset($enabled_languages[$preferred_language])) {
        $response = $this->httpKernel->handle($request, $type, $catch);
        $response->headers->setCookie(
          new \Symfony\Component\HttpFoundation\Cookie(
            'redirect_site_lang', 
            $current_prefix, 
            time() + 86400 // 1 día.
          )
        );

        // Si la ruta apunta a un nodo.
        if (strpos($internal_path, '/node/') === 0) {
          $path_segments = explode('/', trim($internal_path, '/'));
          $node_id = $path_segments[1];
          if ($node_id) {
            $node_storage = $this->entityTypeManager->getStorage('node');
            if ($node = $node_storage->load($node_id)) {
              $prefix_langcode = $this->languageManager->getDefaultLanguage()->getId();
  
              if ($node->hasTranslation($preferred_language)) {
                $prefix_langcode = $preferred_language;
                $node = $node->getTranslation($prefix_langcode);
              }
              $redirect_url = $this->aliasManager->getAliasByPath(
                $node->toUrl()->toString(), 
                $prefix_langcode
              );
  
              // Redirección al alias traducido.
              $response = new RedirectResponse($redirect_url);
              $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie(
                  'redirect_site_lang', 
                  $prefix_langcode, 
                  time() + 86400 // 1 día.
                )
              );
  
              return $response;
            }
          }
        }
      }
    }

    // Si no se cumplen las condiciones, continúa con la solicitud.
    return $this->httpKernel->handle($request, $type, $catch);
  }  
}