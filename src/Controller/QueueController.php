<?php

/**
 * Controller for Lab Order Queue HTTP actions.
 * CSRF validation on every POST, ACL checks on all routes.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2026 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\OpenEMR\Modules\LabOrderQueue\Controller;

use Juggernaut\OpenEMR\Modules\LabOrderQueue\Exception\LabOrderQueueException;
use Juggernaut\OpenEMR\Modules\LabOrderQueue\Service\LabOrderQueueService;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use Twig\Environment;

class QueueController
{
    private LabOrderQueueService $service;
    private Environment $twig;

    public function __construct(?LabOrderQueueService $service = null)
    {
        $this->service = $service ?? new LabOrderQueueService();
        $twigContainer = new TwigContainer($this->getTemplatePath(), $GLOBALS['kernel']);
        $this->twig = $twigContainer->getTwig();
    }

    /**
     * Route an action to the appropriate handler.
     *
     * @param string $action
     * @return void
     */
    public function dispatch(string $action): void
    {
        if (!$this->checkAcl()) {
            $this->sendAccessDenied();
            return;
        }

        match ($action) {
            'new' => $this->showForm(),
            'save' => $this->saveOrder(),
            'list' => $this->listQueue(),
            'global_list' => $this->globalList(),
            'view' => $this->viewOrder(),
            'attach' => $this->attachOrder(),
            'cancel' => $this->cancelOrder(),
            default => $this->listQueue(),
        };
    }

    /**
     * Show the order creation form for a patient.
     */
    public function showForm(): void
    {
        $pid = (int) ($_GET['pid'] ?? \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 0);
        if ($pid <= 0) {
            $this->sendError('No patient selected.');
            return;
        }

        $queueId = (int) ($_GET['queue_id'] ?? 0);
        $order = null;
        if ($queueId > 0) {
            $order = $this->service->getQueuedOrderById($queueId);
        }

        $labProviders = $this->service->getActiveLabProviders();
        $providers = $this->service->getOrderingProviders();

        echo $this->twig->render(
            'queue/order_form.html.twig',
            [
                'pid' => $pid,
                'order' => $order,
                'labProviders' => $labProviders,
                'providers' => $providers,
                'currentProviderId' => \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()->get('authUserID') ?? 0,
                'csrfToken' => CsrfUtils::collectCsrfToken(\OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()),
                'webroot' => $GLOBALS['webroot'],
                'modulePublicPath' => $this->getModulePublicUrl(),
            ]
        );
    }

    /**
     * Save a new queued order (POST).
     */
    public function saveOrder(): void
    {
        if (!$this->verifyCsrf()) {
            return;
        }

        $pid = (int) ($_POST['pid'] ?? 0);
        $providerId = (int) ($_POST['provider_id'] ?? 0);

        $orderData = [
            'lab_id' => (int) ($_POST['lab_id'] ?? 0),
            'date_ordered' => $_POST['date_ordered'] ?? date('Y-m-d'),
            'order_priority' => $_POST['order_priority'] ?? 'normal',
            'clinical_hx' => $_POST['clinical_hx'] ?? '',
            'patient_instructions' => $_POST['patient_instructions'] ?? '',
            'order_psc' => (int) ($_POST['order_psc'] ?? 0),
            'specimen_fasting' => $_POST['specimen_fasting'] ?? '',
            'specimen_volume' => $_POST['specimen_volume'] ?? '',
            'procedure_order_type' => $_POST['procedure_order_type'] ?? 'laboratory_test',
            'notes' => $_POST['notes'] ?? '',
        ];

        // Build procedures array from POST arrays
        $procedures = [];
        $procCodes = $_POST['proc_code'] ?? [];
        $procNames = $_POST['proc_name'] ?? [];
        $procDiagnoses = $_POST['proc_diagnoses'] ?? [];
        $procTitles = $_POST['proc_order_title'] ?? [];
        $procTransport = $_POST['proc_transport'] ?? [];
        $procTypes = $_POST['proc_type'] ?? [];

        foreach ($procCodes as $i => $code) {
            $procedures[] = [
                'procedure_code' => $code,
                'procedure_name' => $procNames[$i] ?? '',
                'diagnoses' => $procDiagnoses[$i] ?? '',
                'procedure_order_title' => $procTitles[$i] ?? '',
                'transport' => $procTransport[$i] ?? '',
                'procedure_type' => $procTypes[$i] ?? 'laboratory_test',
            ];
        }

        try {
            $queueId = $this->service->createQueuedOrder(
                $pid,
                $providerId,
                $orderData,
                $procedures
            );

            $this->sendJsonResponse([
                'success' => true,
                'queue_id' => $queueId,
                'message' => xl('Order queued successfully.'),
            ]);
        } catch (LabOrderQueueException $e) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * List queued orders for a specific patient.
     */
    public function listQueue(): void
    {
        $pid = (int) ($_GET['pid'] ?? \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 0);
        if ($pid <= 0) {
            $this->sendError('No patient selected.');
            return;
        }

        $orders = $this->service->getQueuedOrdersForPatient($pid);
        $encounters = $this->service->getPatientEncounters($pid);

        echo $this->twig->render(
            'queue/queue_list.html.twig',
            [
                'pid' => $pid,
                'orders' => $orders,
                'encounters' => $encounters,
                'csrfToken' => CsrfUtils::collectCsrfToken(\OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()),
                'webroot' => $GLOBALS['webroot'],
                'modulePublicPath' => $this->getModulePublicUrl(),
            ]
        );
    }

    /**
     * Global list of all pending queued orders across all patients.
     */
    public function globalList(): void
    {
        $orders = $this->service->getAllPendingOrders();

        echo $this->twig->render(
            'queue/queue_list.html.twig',
            [
                'pid' => 0,
                'orders' => $orders,
                'encounters' => [],
                'globalView' => true,
                'csrfToken' => CsrfUtils::collectCsrfToken(\OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()),
                'webroot' => $GLOBALS['webroot'],
                'modulePublicPath' => $this->getModulePublicUrl(),
            ]
        );
    }

    /**
     * View a single queued order detail.
     */
    public function viewOrder(): void
    {
        $queueId = (int) ($_GET['queue_id'] ?? 0);
        if ($queueId <= 0) {
            $this->sendError('No queue ID specified.');
            return;
        }

        $order = $this->service->getQueuedOrderById($queueId);
        if ($order === null) {
            $this->sendError('Queue record not found.');
            return;
        }

        $pid = (int) $order['patient_id'];
        $encounters = $this->service->getPatientEncounters($pid);

        echo $this->twig->render(
            'queue/order_form.html.twig',
            [
                'pid' => $pid,
                'order' => $order,
                'encounters' => $encounters,
                'readOnly' => true,
                'labProviders' => $this->service->getActiveLabProviders(),
                'providers' => $this->service->getOrderingProviders(),
                'currentProviderId' => \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()->get('authUserID') ?? 0,
                'csrfToken' => CsrfUtils::collectCsrfToken(\OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()),
                'webroot' => $GLOBALS['webroot'],
                'modulePublicPath' => $this->getModulePublicUrl(),
            ]
        );
    }

    /**
     * Attach a queued order to an encounter (POST).
     */
    public function attachOrder(): void
    {
        if (!$this->verifyCsrf()) {
            return;
        }

        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $encounterId = (int) ($_POST['encounter_id'] ?? 0);
        $pid = (int) ($_POST['pid'] ?? 0);

        try {
            $this->service->attachToEncounter($queueId, $encounterId, $pid);
            $this->sendJsonResponse([
                'success' => true,
                'message' => xl('Order attached to encounter successfully.'),
            ]);
        } catch (LabOrderQueueException $e) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel a queued order (POST).
     */
    public function cancelOrder(): void
    {
        if (!$this->verifyCsrf()) {
            return;
        }

        $queueId = (int) ($_POST['queue_id'] ?? 0);

        try {
            $this->service->cancelQueuedOrder($queueId);
            $this->sendJsonResponse([
                'success' => true,
                'message' => xl('Order cancelled.'),
            ]);
        } catch (LabOrderQueueException $e) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check ACL for lab order access.
     */
    private function checkAcl(): bool
    {
        return AclMain::aclCheckCore('patients', 'lab');
    }

    /**
     * Verify CSRF token on POST requests.
     * Sends a 403 JSON response if invalid.
     *
     * @return bool
     */
    private function verifyCsrf(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }

        $token = $_POST['csrf_token_form'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!CsrfUtils::verifyCsrfToken($token)) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => xl('CSRF token validation failed.'),
            ], 403);
            return false;
        }

        return true;
    }

    /**
     * Send a JSON response.
     *
     * @param array $data
     * @param int   $httpCode
     */
    private function sendJsonResponse(array $data, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Send an access denied page.
     */
    private function sendAccessDenied(): void
    {
        http_response_code(403);
        echo $this->twig->render(
            'queue/queue_list.html.twig',
            [
                'pid' => 0,
                'orders' => [],
                'encounters' => [],
                'errorMessage' => xl('Access denied. You do not have permission to view lab orders.'),
                'csrfToken' => '',
                'webroot' => $GLOBALS['webroot'] ?? '',
                'modulePublicPath' => $this->getModulePublicUrl(),
            ]
        );
    }

    /**
     * Send an error page.
     *
     * @param string $message
     */
    private function sendError(string $message): void
    {
        echo $this->twig->render(
            'queue/queue_list.html.twig',
            [
                'pid' => 0,
                'orders' => [],
                'encounters' => [],
                'errorMessage' => $message,
                'csrfToken' => CsrfUtils::collectCsrfToken(\OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()),
                'webroot' => $GLOBALS['webroot'] ?? '',
                'modulePublicPath' => $this->getModulePublicUrl(),
            ]
        );
    }

    private function getTemplatePath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
    }

    private function getModulePublicUrl(): string
    {
        return $GLOBALS['webroot']
            . '/interface/modules/custom_modules/oe-module-lab-order-queue/public/';
    }
}
