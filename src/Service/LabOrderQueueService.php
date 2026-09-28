<?php

/**
 * Service layer for Lab Order Queue business logic.
 * Validates input, coordinates repository operations, and provides
 * helper data for UI rendering.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2026 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\OpenEMR\Modules\LabOrderQueue\Service;

use Juggernaut\OpenEMR\Modules\LabOrderQueue\Exception\LabOrderQueueException;
use Juggernaut\OpenEMR\Modules\LabOrderQueue\Repository\LabOrderQueueRepository;
use OpenEMR\Common\Database\QueryUtils;

class LabOrderQueueService
{
    private LabOrderQueueRepository $repository;

    public function __construct(?LabOrderQueueRepository $repository = null)
    {
        $this->repository = $repository ?? new LabOrderQueueRepository();
    }

    /**
     * Validate and create a queued lab order.
     *
     * @param int   $patientId
     * @param int   $providerId
     * @param array $orderData
     * @param array $procedures
     * @return int Queue ID
     * @throws LabOrderQueueException
     */
    public function createQueuedOrder(
        int $patientId,
        int $providerId,
        array $orderData,
        array $procedures
    ): int {
        $errors = $this->validateOrderData($patientId, $providerId, $orderData, $procedures);
        if (!empty($errors)) {
            throw new LabOrderQueueException(
                'Validation failed: ' . implode('; ', $errors)
            );
        }

        return $this->repository->createQueuedOrder(
            $patientId,
            $providerId,
            $orderData,
            $procedures
        );
    }

    /**
     * Validate order data before queue insertion.
     * Diagnosis is NOT required at queue time (deferred to transmit).
     *
     * @param int   $patientId
     * @param int   $providerId
     * @param array $orderData
     * @param array $procedures
     * @return array Validation error messages (empty if valid)
     */
    public function validateOrderData(
        int $patientId,
        int $providerId,
        array $orderData,
        array $procedures
    ): array {
        $errors = [];

        if ($patientId <= 0) {
            $errors[] = 'Patient ID is required';
        }

        if ($providerId <= 0) {
            $errors[] = 'Provider ID is required';
        }

        $labId = (int) ($orderData['lab_id'] ?? 0);
        if ($labId <= 0) {
            $errors[] = 'Lab provider selection is required';
        } else {
            // Verify lab provider exists and is active
            $lab = QueryUtils::querySingleRow(
                "SELECT ppid FROM procedure_providers WHERE ppid = ? AND active = 1",
                [$labId]
            );
            if (empty($lab)) {
                $errors[] = 'Selected lab provider is not active or does not exist';
            }
        }

        if (empty($procedures)) {
            $errors[] = 'At least one procedure is required';
        } else {
            foreach ($procedures as $index => $proc) {
                $code = trim($proc['procedure_code'] ?? '');
                $name = trim($proc['procedure_name'] ?? '');
                if (empty($code) && empty($name)) {
                    $errors[] = 'Procedure item ' . ($index + 1) . ' must have a code or name';
                }
            }
        }

        return $errors;
    }

    /**
     * Validate and attach a queued order to an encounter.
     *
     * @param int $queueId
     * @param int $encounterId
     * @param int $pid
     * @return bool
     * @throws LabOrderQueueException
     */
    public function attachToEncounter(int $queueId, int $encounterId, int $pid): bool
    {
        if ($queueId <= 0) {
            throw new LabOrderQueueException('Invalid queue ID');
        }

        if ($encounterId <= 0) {
            throw new LabOrderQueueException('Invalid encounter ID');
        }

        if ($pid <= 0) {
            throw new LabOrderQueueException('Invalid patient ID');
        }

        // Verify the encounter exists and belongs to the patient
        $encounter = QueryUtils::querySingleRow(
            "SELECT encounter FROM form_encounter WHERE encounter = ? AND pid = ?",
            [$encounterId, $pid]
        );

        if (empty($encounter)) {
            throw new LabOrderQueueException(
                'Encounter does not exist or does not belong to the specified patient'
            );
        }

        // Verify the queue record belongs to the same patient
        $queueRecord = $this->repository->getQueuedOrderById($queueId);
        if ($queueRecord === null) {
            throw new LabOrderQueueException('Queue record not found');
        }

        if ((int) $queueRecord['patient_id'] !== $pid) {
            throw new LabOrderQueueException(
                'Queue record does not belong to the specified patient'
            );
        }

        $authorized = \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()->get('userauthorized') ?? '0';

        return $this->repository->attachToEncounter(
            $queueId,
            $encounterId,
            $pid,
            $authorized
        );
    }

    /**
     * Cancel a queued order with validation.
     *
     * @param int $queueId
     * @return bool
     * @throws LabOrderQueueException
     */
    public function cancelQueuedOrder(int $queueId): bool
    {
        if ($queueId <= 0) {
            throw new LabOrderQueueException('Invalid queue ID');
        }

        return $this->repository->cancelQueuedOrder($queueId);
    }

    /**
     * Get pending queued orders for a specific patient.
     *
     * @param int $patientId
     * @return array
     */
    public function getQueuedOrdersForPatient(int $patientId): array
    {
        return $this->repository->getQueuedOrdersForPatient($patientId);
    }

    /**
     * Get a single queued order with all details.
     *
     * @param int $queueId
     * @return array|null
     */
    public function getQueuedOrderById(int $queueId): ?array
    {
        $order = $this->repository->getQueuedOrderById($queueId);
        if ($order !== null) {
            $order['procedures'] = $this->repository->getOrderProcedures(
                (int) $order['procedure_order_id']
            );
        }

        return $order;
    }

    /**
     * Get all pending queued orders across all patients.
     *
     * @return array
     */
    public function getAllPendingOrders(): array
    {
        return $this->repository->getAllPendingOrders();
    }

    /**
     * Count pending orders for a patient (for alert banner).
     *
     * @param int $patientId
     * @return int
     */
    public function countPendingForPatient(int $patientId): int
    {
        return $this->repository->countPendingForPatient($patientId);
    }

    /**
     * Get active lab providers for the order form dropdown.
     *
     * @return array
     */
    public function getActiveLabProviders(): array
    {
        return QueryUtils::fetchRecords(
            "SELECT ppid, name, npi
            FROM procedure_providers
            WHERE active = 1
            ORDER BY name",
            []
        );
    }

    /**
     * Get active providers (users) for the ordering provider dropdown.
     *
     * @return array
     */
    public function getOrderingProviders(): array
    {
        return QueryUtils::fetchRecords(
            "SELECT id, fname, lname, npi
            FROM users
            WHERE active = 1
                AND authorized = 1
            ORDER BY lname, fname",
            []
        );
    }

    /**
     * Get patient encounters for the attach dropdown.
     *
     * @param int $patientId
     * @return array
     */
    public function getPatientEncounters(int $patientId): array
    {
        return QueryUtils::fetchRecords(
            "SELECT fe.encounter, fe.date, fe.reason
            FROM form_encounter fe
            WHERE fe.pid = ?
            ORDER BY fe.date DESC
            LIMIT 20",
            [$patientId]
        );
    }
}
