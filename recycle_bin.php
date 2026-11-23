<?php
require_once __DIR__ . '/auth.php';
requireAuth($pdo);
require_once __DIR__ . '/functions/delete.php';
require_once __DIR__ . '/functions/view.php';
require_once __DIR__ . '/functions/layout.php';
$error = '';
$flash = $_SESSION['recycle_flash'] ?? null;
unset($_SESSION['recycle_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        if (isset($_POST['restore_pet'])) {
            restorePet($pdo, $_POST['restore_pet'], currentUserId());
            $_SESSION['recycle_flash'] = ['type' => 'success', 'text' => 'Pet restored successfully.'];
        } else {
            throw new InvalidArgumentException('Invalid form submission.');
        }
        header('Location: recycle_bin.php');
        exit;
    } catch (InvalidArgumentException | OutOfBoundsException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Pet Grooming Salon recycle bin restore failed: ' . $e->getMessage());
        $error = 'The pet could not be restored because of a database error.';
    }
}

try {
    $deletedPets = viewDeletedPets($pdo);
} catch (PDOException $e) {
    error_log('Pet Grooming Salon recycle bin list failed: ' . $e->getMessage());
    $deletedPets = [];
    $error = 'Unable to load the recycle bin. Check the database setup and reload this page.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recycle Bin | Pet Grooming Salon</title>
    <link rel="stylesheet" href="style.css">
    <script src="confirm-delete.js" defer></script>
</head>
<body>
    <?php renderNavigation('recycle'); ?>
    <main class="page-shell">
        <div class="page-title"><div><p class="eyebrow">Recycle bin</p><h1>Deleted Pets</h1></div><p class="muted">Soft-deleted pets await restoration here. Each entry shows who deleted it and when.</p></div>
        <?php if ($error !== ''): ?><p class="message error" role="alert"><?php echo escapeHtml($error); ?></p><?php endif; ?>
        <?php if ($flash !== null): ?><p class="message <?php echo escapeHtml($flash['type']); ?>" role="status"><?php echo escapeHtml($flash['text']); ?></p><?php endif; ?>
        <section class="card">
            <div class="table-wrap"><table>
                <thead><tr><th>Pet ID</th><th>Image</th><th>Pet name</th><th>Species</th><th>Breed</th><th>Owner</th><th>Deleted by</th><th>Deleted at</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($deletedPets as $p): ?><tr>
                    <td><?php echo (int) $p['pet_id']; ?></td>
                    <td>
                        <?php if (!empty($p['pet_image'])): ?>
                            <img src="<?php echo escapeHtml($p['pet_image']); ?>" alt="Pet image" class="pet-image-thumb">
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo escapeHtml($p['pet_name']); ?></td>
                    <td><?php echo escapeHtml($p['species']); ?></td>
                    <td><?php echo escapeHtml($p['breed'] ?? ''); ?></td>
                    <td><?php echo escapeHtml($p['owner_name']); ?></td>
                    <td><?php echo escapeHtml($p['deleted_by_name'] ?? 'Former / Unknown'); ?></td>
                    <td><?php echo escapeHtml($p['deleted_at']); ?></td>
                    <td class="actions">
                        <form method="POST" action="recycle_bin.php" class="inline-form" data-confirm="Restore this pet to the table?">
                            <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($_SESSION['csrf_token']); ?>">
                            <button class="button secondary" type="submit" name="restore_pet" value="<?php echo (int) $p['pet_id']; ?>">Restore</button>
                        </form>
                    </td>
                </tr><?php endforeach; ?>
                <?php if ($deletedPets === []): ?><tr><td colspan="9" class="empty-state">The recycle bin is empty. No pets have been deleted.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
        <p class="muted">Restored pets reappear on the <a href="pets.php">Manage Pets</a> page. Deletions are also recorded in <a href="audit_logs.php">Audit Logs</a>.</p>
    </main>
</body>
</html>
