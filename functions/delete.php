<?php
require_once __DIR__ . '/audit.php';

function deleteOwner(PDO $pdo, $ownerId, int $userId): void
{
    $id = validateRecordId($ownerId, 'owner');
    $pdo->beginTransaction();
    try {
        $owner = loadAuditSnapshot($pdo, 'Pet Owner', $id);
        $petsStmt = $pdo->prepare('SELECT pet_id FROM pets WHERE owner_id = :owner_id ORDER BY pet_id');
        $petsStmt->execute(['owner_id' => $id]);
        $petIds = $petsStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($petIds as $petId) {
            $pet = loadAuditSnapshot($pdo, 'Pet', (int) $petId);
            logAuditEvent($pdo, $userId, 'DELETE', 'Pet', (int) $petId, $pet, null);
        }
        logAuditEvent($pdo, $userId, 'DELETE', 'Pet Owner', $id, $owner, null);
        $stmt = $pdo->prepare('DELETE FROM pet_owners WHERE owner_id = :owner_id');
        $stmt->execute(['owner_id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new OutOfBoundsException('Owner not found.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Soft-delete a pet: mark it as deleted instead of removing the row, so the
// record can be restored from the recycle bin later.
function deletePet(PDO $pdo, $petId, int $userId): void
{
    $id = validateRecordId($petId, 'pet');
    $pdo->beginTransaction();
    try {
        $pet = loadAuditSnapshot($pdo, 'Pet', $id);
        $stmt = $pdo->prepare(
            'UPDATE pets SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by '
            . 'WHERE pet_id = :pet_id AND deleted_at IS NULL'
        );
        $stmt->execute(['deleted_by' => $userId, 'pet_id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new OutOfBoundsException('Pet not found or already deleted.');
        }
        logAuditEvent($pdo, $userId, 'DELETE', 'Pet', $id, $pet, null);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Restore a soft-deleted pet so it appears in the pet table again.
function restorePet(PDO $pdo, $petId, int $userId): void
{
    $id = validateRecordId($petId, 'pet');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE pets SET deleted_at = NULL, deleted_by = NULL '
            . 'WHERE pet_id = :pet_id AND deleted_at IS NOT NULL'
        );
        $stmt->execute(['pet_id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new OutOfBoundsException('Pet not found or not deleted.');
        }
        $pet = loadAuditSnapshot($pdo, 'Pet', $id);
        logAuditEvent($pdo, $userId, 'INSERT', 'Pet', $id, null, $pet);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
