<?php

/**
 * Repository for Lab Order Queue database operations.
 * All queries use QueryUtils with bound parameters.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2026 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\OpenEMR\Modules\LabOrderQueue\Repository;

use Juggernaut\OpenEMR\Modules\LabOrderQueue\Exception\LabOrderQueueException;
use OpenEMR\Common\Database\QueryUtils;

class LabOrderQueueRepository
{
    /**
     * Create a queued lab order.
     * Inserts into procedure_order (encounter_id = 0), procedure_order_code,
     * and the lab_order_queue tracking table within a transaction.
     *
     * @param int   $patientId
     * @param int   $providerId
     * @param array $orderData Keys: lab_id, date_ordered, order_priority,
     *                         clinical_hx, patient_instructions, order_psc,
     *                         specimen_fasting, specimen_volume, notes
     * @param array $procedures Array of procedure items, each containing:
     *                          procedure_type_id, procedure_code, procedure_name,
     *                          diagnoses, procedure_order_title, transport,
     *                          procedure_type
     * @return int The lab_order_queue.id of the created queue entry
     * @throws LabOrderQueueException
     */
    public function createQueuedOrder(int $patientId, int $providerId, array $orderData, array $procedures): int
    {
        QueryUtils::startTransaction();

        try {
            // Insert into procedure_order with encounter_id = 0
            $procedureOrderId = QueryUtils::sqlInsert(
                "INSERT INTO procedure_order SET
                    provider_id = ?,
                    patient_id = ?,
                    encounter_id = 0,
                    lab_id = ?,
                    date_ordered = ?,
                    order_priority = ?,
                    order_status = 'pending',
                    clinical_hx = ?,
                    patient_instructions = ?,
                    order_psc = ?,
                    specimen_fasting = ?,
                    specimen_volume = ?,
                    procedure_order_type = ?",
                [
                    $providerId,
                    $patientId,
                    (int) ($orderData['lab_id'] ?? 0),
                    $orderData['date_ordered'] ?? date('Y-m-d'),
                    $orderData['order_priority'] ?? 'normal',
                    trim($orderData['clinical_hx'] ?? ''),
                    trim($orderData['patient_instructions'] ?? ''),
                    (int) ($orderData['order_psc'] ?? 0),
                    $orderData['specimen_fasting'] ?? '',
                    $orderData['specimen_volume'] ?? '',
                    $orderData['procedure_order_type'] ?? 'laboratory_test',
                ]
            );

            if (empty($procedureOrderId)) {
                throw new LabOrderQueueException('Failed to insert procedure_order record');
            }

            // Insert procedure_order_code rows
            $seq = 1;
            foreach ($procedures as $proc) {
                QueryUtils::sqlInsert(
                    "INSERT INTO procedure_order_code SET
                        procedure_order_id = ?,
                        procedure_order_seq = ?,
                        procedure_code = ?,
                        procedure_name = ?,
                        diagnoses = ?,
                        procedure_order_title = ?,
                        transport = ?,
                        procedure_type = ?",
                    [
                        $procedureOrderId,
                        $seq,
                        trim($proc['procedure_code'] ?? ''),
                        trim($proc['procedure_name'] ?? ''),
                        trim($proc['diagnoses'] ?? ''),
                        trim($proc['procedure_order_title'] ?? ''),
                        trim($proc['transport'] ?? ''),
                        trim($proc['procedure_type'] ?? 'laboratory_test'),
                    ]
                );
                $seq++;
            }

            // Insert lab_order_queue tracking row
            $queueId = QueryUtils::sqlInsert(
                "INSERT INTO lab_order_queue SET
                    procedure_order_id = ?,
                    patient_id = ?,
                    provider_id = ?,
                    status = 'pending',
                    notes = ?",
                [
                    $procedureOrderId,
                    $patientId,
                    $providerId,
                    trim($orderData['notes'] ?? ''),
                ]
            );

            if (empty($queueId)) {
                throw new LabOrderQueueException('Failed to insert lab_order_queue record');
            }

            QueryUtils::commitTransaction();
            return (int) $queueId;
        } catch (\Exception $e) {
            QueryUtils::rollbackTransaction();
            if ($e instanceof LabOrderQueueException) {
                throw $e;
            }
            throw new LabOrderQueueException(
                'Failed to create queued order: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get all pending queued orders for a patient.
     * Syncs status first, then joins lab_order_queue with procedure_order
     * and procedure_providers to return order details.
     *
     * @param int $patientId
     * @return array
     */
    public function getQueuedOrdersForPatient(int $patientId): array
    {
        // Reconcile any orders attached by core outside this module
        $this->syncPendingStatus($patientId);

        return QueryUtils::fetchRecords(
            "SELECT
                q.id AS queue_id,
                q.procedure_order_id,
                q.status,
                q.notes,
                q.created_at,
                po.lab_id,
                po.date_ordered,
                po.order_priority,
                po.clinical_hx,
                po.order_psc,
                po.procedure_order_type,
                pp.name AS lab_name,
                u.fname AS provider_fname,
                u.lname AS provider_lname
            FROM lab_order_queue q
            JOIN procedure_order po ON po.procedure_order_id = q.procedure_order_id
            LEFT JOIN procedure_providers pp ON pp.ppid = po.lab_id
            LEFT JOIN users u ON u.id = q.provider_id
            WHERE q.patient_id = ?
                AND q.status = 'pending'
            ORDER BY q.created_at DESC",
            [$patientId]
        );
    }

    /**
     * Get a single queued order by queue ID with full order details.
     *
     * @param int $queueId
     * @return array|null
     */
    public function getQueuedOrderById(int $queueId): ?array
    {
        $row = QueryUtils::querySingleRow(
            "SELECT
                q.id AS queue_id,
                q.procedure_order_id,
                q.patient_id,
                q.provider_id,
                q.status,
                q.encounter_id,
                q.notes,
                q.created_at,
                q.attached_at,
                q.cancelled_at,
                po.lab_id,
                po.date_ordered,
                po.order_priority,
                po.order_status,
                po.clinical_hx,
                po.patient_instructions,
                po.order_psc,
                po.specimen_fasting,
                po.specimen_volume,
                po.procedure_order_type,
                pp.name AS lab_name,
                u.fname AS provider_fname,
                u.lname AS provider_lname
            FROM lab_order_queue q
            JOIN procedure_order po ON po.procedure_order_id = q.procedure_order_id
            LEFT JOIN procedure_providers pp ON pp.ppid = po.lab_id
            LEFT JOIN users u ON u.id = q.provider_id
            WHERE q.id = ?",
            [$queueId]
        );

        return $row ?: null;
    }

    /**
     * Get procedure_order_code rows for a given procedure order.
     *
     * @param int $procedureOrderId
     * @return array
     */
    public function getOrderProcedures(int $procedureOrderId): array
    {
        return QueryUtils::fetchRecords(
            "SELECT
                procedure_order_seq,
                procedure_code,
                procedure_name,
                diagnoses,
                procedure_order_title,
                transport,
                procedure_type
            FROM procedure_order_code
            WHERE procedure_order_id = ?
            ORDER BY procedure_order_seq",
            [$procedureOrderId]
        );
    }

    /**
     * Attach a queued order to an encounter.
     * Updates procedure_order.encounter_id, registers in the forms table
     * via addForm(), and marks the queue entry as attached.
     *
     * @param int    $queueId
     * @param int    $encounterId
     * @param int    $pid
     * @param string $authorized
     * @return bool
     * @throws LabOrderQueueException
     */
    public function attachToEncounter(
        int $queueId,
        int $encounterId,
        int $pid,
        string $authorized = '0'
    ): bool {
        $queueRecord = $this->getQueuedOrderById($queueId);

        if ($queueRecord === null) {
            throw new LabOrderQueueException("Queue record not found: $queueId");
        }

        if ($queueRecord['status'] !== 'pending') {
            throw new LabOrderQueueException(
                "Cannot attach order with status: {$queueRecord['status']}"
            );
        }

        $procedureOrderId = (int) $queueRecord['procedure_order_id'];

        QueryUtils::startTransaction();

        try {
            // Update procedure_order with the real encounter ID
            QueryUtils::sqlStatementThrowException(
                "UPDATE procedure_order
                SET encounter_id = ?
                WHERE procedure_order_id = ?",
                [$encounterId, $procedureOrderId]
            );

            // Build the form label from lab name
            $labName = $queueRecord['lab_name'] ?? 'Lab';
            $formLabel = $labName . '-' . $procedureOrderId;

            // Register in the forms table so the encounter UI picks it up.
            // addForm() is a global function from library/forms.inc.php.
            if (function_exists('addForm')) {
                addForm(
                    $encounterId,
                    $formLabel,
                    $procedureOrderId,
                    'procedure_order',
                    $pid,
                    $authorized
                );
            } else {
                // Fallback: direct insert if addForm is not loaded
                QueryUtils::sqlInsert(
                    "INSERT INTO forms (date, encounter, form_name, form_id, pid, user, groupname, authorized, formdir)
                    VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, 'procedure_order')",
                    [
                        $encounterId,
                        $formLabel,
                        $procedureOrderId,
                        $pid,
                        \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()->get('authUser') ?? '',
                        \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession()->get('authProvider') ?? '',
                        $authorized,
                    ]
                );
            }

            // Mark queue entry as attached
            QueryUtils::sqlStatementThrowException(
                "UPDATE lab_order_queue
                SET status = 'attached',
                    encounter_id = ?,
                    attached_at = NOW()
                WHERE id = ?",
                [$encounterId, $queueId]
            );

            QueryUtils::commitTransaction();
            return true;
        } catch (\Exception $e) {
            QueryUtils::rollbackTransaction();
            if ($e instanceof LabOrderQueueException) {
                throw $e;
            }
            throw new LabOrderQueueException(
                'Failed to attach order to encounter: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Cancel a queued order. Sets status to cancelled and timestamps it.
     * Does NOT delete the procedure_order row — it remains with encounter_id = 0
     * but is marked inactive.
     *
     * @param int $queueId
     * @return bool
     * @throws LabOrderQueueException
     */
    public function cancelQueuedOrder(int $queueId): bool
    {
        $queueRecord = $this->getQueuedOrderById($queueId);

        if ($queueRecord === null) {
            throw new LabOrderQueueException("Queue record not found: $queueId");
        }

        if ($queueRecord['status'] !== 'pending') {
            throw new LabOrderQueueException(
                "Cannot cancel order with status: {$queueRecord['status']}"
            );
        }

        QueryUtils::startTransaction();

        try {
            // Mark procedure_order as inactive
            QueryUtils::sqlStatementThrowException(
                "UPDATE procedure_order
                SET activity = 0
                WHERE procedure_order_id = ?",
                [(int) $queueRecord['procedure_order_id']]
            );

            // Mark queue entry as cancelled
            QueryUtils::sqlStatementThrowException(
                "UPDATE lab_order_queue
                SET status = 'cancelled',
                    cancelled_at = NOW()
                WHERE id = ?",
                [$queueId]
            );

            QueryUtils::commitTransaction();
            return true;
        } catch (\Exception $e) {
            QueryUtils::rollbackTransaction();
            if ($e instanceof LabOrderQueueException) {
                throw $e;
            }
            throw new LabOrderQueueException(
                'Failed to cancel queued order: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Sync queue status with actual procedure_order state.
     * If the core system attached an order (set encounter_id > 0) outside
     * this module, mark the queue record as attached so our tracking stays accurate.
     *
     * @param int $patientId
     * @return int Number of records synced
     */
    public function syncPendingStatus(int $patientId): int
    {
        // Find queue records that are 'pending' but whose procedure_order
        // has already been attached to an encounter by the core system
        $staleRecords = QueryUtils::fetchRecords(
            "SELECT q.id AS queue_id, po.encounter_id
            FROM lab_order_queue q
            JOIN procedure_order po ON po.procedure_order_id = q.procedure_order_id
            WHERE q.patient_id = ?
                AND q.status = 'pending'
                AND po.encounter_id > 0",
            [$patientId]
        );

        $synced = 0;
        foreach ($staleRecords as $record) {
            QueryUtils::sqlStatementThrowException(
                "UPDATE lab_order_queue
                SET status = 'attached',
                    encounter_id = ?,
                    attached_at = NOW()
                WHERE id = ?",
                [(int) $record['encounter_id'], (int) $record['queue_id']]
            );
            $synced++;
        }

        return $synced;
    }

    /**
     * Count pending queued orders for a patient.
     * Runs syncPendingStatus first to reconcile any orders that were
     * attached by the core system outside this module.
     *
     * @param int $patientId
     * @return int
     */
    public function countPendingForPatient(int $patientId): int
    {
        // Reconcile first — catch orders attached by core forms.php
        $this->syncPendingStatus($patientId);

        $row = QueryUtils::querySingleRow(
            "SELECT COUNT(*) AS cnt
            FROM lab_order_queue
            WHERE patient_id = ?
                AND status = 'pending'",
            [$patientId]
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Get all pending queued orders across all patients.
     * Used by the global "Lab Queue" view under Procedures menu.
     *
     * @return array
     */
    public function getAllPendingOrders(): array
    {
        return QueryUtils::fetchRecords(
            "SELECT
                q.id AS queue_id,
                q.procedure_order_id,
                q.patient_id,
                q.provider_id,
                q.notes,
                q.created_at,
                po.lab_id,
                po.date_ordered,
                po.order_priority,
                po.procedure_order_type,
                pp.name AS lab_name,
                pd.fname AS patient_fname,
                pd.lname AS patient_lname,
                u.fname AS provider_fname,
                u.lname AS provider_lname
            FROM lab_order_queue q
            JOIN procedure_order po ON po.procedure_order_id = q.procedure_order_id
            LEFT JOIN procedure_providers pp ON pp.ppid = po.lab_id
            LEFT JOIN patient_data pd ON pd.pid = q.patient_id
            LEFT JOIN users u ON u.id = q.provider_id
            WHERE q.status = 'pending'
            ORDER BY q.created_at DESC",
            []
        );
    }
}
