<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Api\Controller;

use Shopware\Core\Framework\Adapter\Asset\LastModifiedVersionStrategy;
use Shopware\Core\Framework\Api\ApiDefinition\DefinitionService;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\EntitySchemaGenerator;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\OpenApi3Generator;
use Shopware\Core\Framework\Bundle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Kernel;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"api"})
 */
class InfoController extends AbstractController
{
    /**
     * @var DefinitionService
     */
    private $definitionService;

    /**
     * @var ParameterBagInterface
     */
    private $params;

    /**
     * @var Packages
     */
    private $packages;

    /**
     * @var Kernel
     */
    private $kernel;

    /**
     * @var bool
     */
    private $enableUrlFeature;

    /**
     * @var array
     */
    private $cspTemplates;

    /**
     * @var BusinessEventCollector
     */
    private $eventCollector;

    public function __construct(
        DefinitionService $definitionService,
        ParameterBagInterface $params,
        Kernel $kernel,
        Packages $packages,
        BusinessEventCollector $eventCollector,
        bool $enableUrlFeature = true,
        array $cspTemplates = []
    ) {
        $this->definitionService = $definitionService;
        $this->params = $params;
        $this->packages = $packages;
        $this->kernel = $kernel;
        $this->enableUrlFeature = $enableUrlFeature;
        $this->cspTemplates = $cspTemplates;
        $this->eventCollector = $eventCollector;
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/openapi3.json", defaults={"auth_required"="%shopware.api.api_browser.auth_required_str%"}, name="api.info.openapi3", methods={"GET"})
     *
     * @throws \Exception
     */
    public function info(): JsonResponse
    {
        $data = $this->definitionService->generate(OpenApi3Generator::FORMAT, DefinitionService::API);

        return $this->json($data);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/open-api-schema.json", defaults={"auth_required"="%shopware.api.api_browser.auth_required_str%"}, name="api.info.open-api-schema", methods={"GET"})
     */
    public function openApiSchema(): JsonResponse
    {
        $data = $this->definitionService->getSchema(OpenApi3Generator::FORMAT, DefinitionService::API);

        return $this->json($data);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/entity-schema.json", name="api.info.entity-schema", methods={"GET"})
     */
    public function entitySchema(): JsonResponse
    {
        $data = $this->definitionService->getSchema(EntitySchemaGenerator::FORMAT, DefinitionService::API);

        return $this->json($data);
    }

    /**
     * @Since("6.3.2.0")
     * @Route("/api/_info/events.json", name="api.info.business-events", methods={"GET"})
     */
    public function businessEvents(Context $context): JsonResponse
    {
        $events = $this->eventCollector->collect($context);

        return $this->json($events);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/swagger.html", defaults={"auth_required"="%shopware.api.api_browser.auth_required_str%"}, name="api.info.swagger", methods={"GET"})
     */
    public function infoHtml(Request $request): Response
    {
        $nonce = $request->attributes->get(PlatformRequest::ATTRIBUTE_CSP_NONCE);
        $response = $this->render(
            '@Framework/swagger.html.twig',
            [
                'schemaUrl' => 'api.info.openapi3',
                'cspNonce' => $nonce,
            ]
        );

        $cspTemplate = $this->cspTemplates['administration'] ?? '';
        $cspTemplate = trim($cspTemplate);
        if ($cspTemplate !== '') {
            $csp = str_replace('%nonce%', $nonce, $cspTemplate);
            $csp = str_replace(["\n", "\r"], ' ', $csp);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_info/config", name="api.info.config", methods={"GET"})
     */
    public function config(): JsonResponse
    {
        return $this->json([
            'version' => $this->params->get('kernel.shopware_version'),
            'versionRevision' => $this->params->get('kernel.shopware_version_revision'),
            'adminWorker' => [
                'enableAdminWorker' => $this->params->get('shopware.admin_worker.enable_admin_worker'),
                'transports' => $this->params->get('shopware.admin_worker.transports'),
            ],
            'bundles' => $this->getBundles(),
            'settings' => [
                'enableUrlFeature' => $this->enableUrlFeature,
            ],
        ]);
    }

    /**
     * @Since("6.3.5.0")
     * @Route("/api/_info/version", name="api.info.shopware.version", methods={"GET"})
     * @Route("/api/v1/_info/version", name="api.info.shopware.version_old_version", methods={"GET"})
     */
    public function infoShopwareVersion(): JsonResponse
    {
        return $this->json([
            'version' => $this->params->get('kernel.shopware_version'),
        ]);
    }

    private function getBundles(): array
    {
        $assets = [];
        $package = $this->packages->getPackage('asset');

        foreach ($this->kernel->getBundles() as $bundle) {
            if (!$bundle instanceof Bundle) {
                continue;
            }

            $bundleName = mb_strtolower($bundle->getName());

            $styles = array_map(static function (string $filename) use ($package, $bundleName) {
                $url = 'bundles/' . $bundleName . '/' . $filename;

                return $package->getUrl($url);
            }, $this->getAdministrationStyles($bundle));

            $scripts = array_map(static function (string $filename) use ($package, $bundleName) {
                $url = 'bundles/' . $bundleName . '/' . $filename;

                return $package->getUrl($url);
            }, $this->getAdministrationScripts($bundle));

            if (empty($styles) && empty($scripts)) {
                continue;
            }

            $assets[$bundle->getName()] = [
                'css' => $styles,
                'js' => $scripts,
            ];
        }

        return $assets;
    }

    private function getAdministrationStyles(Bundle $bundle): array
    {
        $path = 'administration/css/' . str_replace('_', '-', $bundle->getContainerPrefix()) . '.css';
        $bundlePath = $bundle->getPath();

        if (!file_exists($bundlePath . '/Resources/public/' . $path)) {
            return [];
        }

        $strategy = new LastModifiedVersionStrategy($bundlePath);

        return [$strategy->applyVersion($path)];
    }

    private function getAdministrationScripts(Bundle $bundle): array
    {
        $path = 'administration/js/' . str_replace('_', '-', $bundle->getContainerPrefix()) . '.js';
        $bundlePath = $bundle->getPath();

        if (!file_exists($bundlePath . '/Resources/public/' . $path)) {
            return [];
        }

        $strategy = new LastModifiedVersionStrategy($bundlePath);

        return [$strategy->applyVersion($path)];
    }
}
