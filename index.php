<?php
// ==========================================
// 1. INI DAN KONEKSI DATABASE (PDO)
// ==========================================
session_start();

$host = 'localhost';
$db   = 'store_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Koneksi Database Gagal: " . $e->getMessage());
}

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper Sanitasi Output (Mencegah XSS)
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// ==========================================
// 2. LOGIKA APLIKASI & PEMROSESAN FORM
// ==========================================
$errors = [];
$success = $_SESSION['success_msg'] ?? '';
unset($_SESSION['success_msg']);

// Ambil data untuk form Edit jika tombol edit diklik
$edit_product = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_product = $stmt->fetch();
}

// Handler Request POST (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi Token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Validasi CSRF gagal.");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $price = filter_var($_POST['price'], FILTER_VALIDATE_FLOAT);
        $stock = filter_var($_POST['stock'], FILTER_VALIDATE_INT);
        $id = $_POST['id'] ?? null;

        // Validasi Sesuai Requirement
        if (strlen($name) < 3) {
            $errors[] = "Nama produk minimal 3 karakter.";
        }
        if ($price === false || $price <= 0) {
            $errors[] = "Harga harus berupa angka positif (> 0).";
        }
        if ($stock === false || $stock < 0) {
            $errors[] = "Stok tidak boleh bernilai negatif (>= 0).";
        }

        // Cek Keunikan Nama Produk
        if (empty($errors)) {
            if ($action === 'create') {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE name = ?");
                $checkStmt->execute([$name]);
            } else {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE name = ? AND id != ?");
                $checkStmt->execute([$name, $id]);
            }
            if ($checkStmt->fetchColumn() > 0) {
                $errors[] = "Nama produk sudah digunakan. Silakan pilih nama lain.";
            }
        }

        // Eksekusi Query jika tidak ada error
        if (empty($errors)) {
            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO products (name, category, price, stock) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $category, $price, $stock]);
                $_SESSION['success_msg'] = "Produk berhasil ditambahkan!";
            } else {
                $stmt = $pdo->prepare("UPDATE products SET name = ?, category = ?, price = ?, stock = ? WHERE id = ?");
                $stmt->execute([$name, $category, $price, $stock, $id]);
                $_SESSION['success_msg'] = "Produk berhasil diperbarui!";
            }
            
            // Post-Redirect-Get (PRG) untuk mencegah duplikasi saat refresh
            header("Location: index.php");
            exit;
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success_msg'] = "Produk berhasil dihapus!";
            header("Location: index.php");
            exit;
        }
    }
}

// Fetch seluruh daftar produk
$products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
?>

<!-- ==========================================
     3. TAMPILAN HTML / CSS
     ========================================== -->
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Produk</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { padding: 20px; background: #f4f6f9; max-width: 1100px; margin: auto; color: #333; }
        
        /* Alert Box */
        .alert { padding: 12px 16px; margin-bottom: 20px; border-radius: 6px; font-weight: 500; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert ul { margin: 0; padding-left: 20px; }

        /* Form styling */
        .form-container { background: white; padding: 24px; border-radius: 8px; margin-bottom: 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .form-group { margin-bottom: 12px; }
        label { display: block; margin-bottom: 6px; font-weight: bold; font-size: 0.9em; }
        input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; }
        
        /* Button styling */
        button { padding: 10px 18px; background: #28a745; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        button:hover { opacity: 0.9; }
        button.btn-danger { background: #dc3545; }
        .btn-cancel { margin-left: 10px; color: #6c757d; text-decoration: none; }

        /* Card grid responsive */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }
        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            word-wrap: break-word;
        }
        .card h3 { margin-top: 0; color: #111; word-break: break-all; }
        .card p { margin: 6px 0; font-size: 0.95em; color: #555; }
        .card-actions { margin-top: 15px; display: flex; gap: 10px; align-items: center; }
        .btn-edit { color: #0056b3; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

    <h1>Product Manager</h1>

    <!-- Alert Notifikasi -->
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Form Create / Update -->
    <div class="form-container">
        <h2><?= $edit_product ? 'Edit Produk' : 'Tambah Produk Baru' ?></h2>
        <form method="POST" action="index.php">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="<?= $edit_product ? 'update' : 'create' ?>">
            <?php if ($edit_product): ?>
                <input type="hidden" name="id" value="<?= e($edit_product['id']) ?>">
            <?php endif; ?>

            <div class="form-group">
                <label>Nama Produk</label>
                <input type="text" name="name" value="<?= e($edit_product['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Kategori</label>
                <input type="text" name="category" value="<?= e($edit_product['category'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Harga (Rp)</label>
                <input type="number" step="0.01" name="price" value="<?= e($edit_product['price'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Stok</label>
                <input type="number" name="stock" value="<?= e($edit_product['stock'] ?? '') ?>" required>
            </div>
            
            <button type="submit"><?= $edit_product ? 'Perbarui Produk' : 'Tambah Produk' ?></button>
            <?php if ($edit_product): ?>
                <a href="index.php" class="btn-cancel">Batal Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Read: Card Responsif -->
    <h2>Daftar Produk</h2>
    <div class="card-grid">
        <?php if (empty($products)): ?>
            <p>Belum ada produk tersimpan.</p>
        <?php else: ?>
            <?php foreach ($products as $p): ?>
                <div class="card">
                    <!-- htmlspecialchars untuk keamanan XSS -->
                    <h3><?= e($p['name']) ?></h3>
                    <p><strong>Kategori:</strong> <?= e($p['category']) ?></p>
                    <p><strong>Harga:</strong> Rp <?= number_format($p['price'], 0, ',', '.') ?></p>
                    <p><strong>Stok:</strong> <?= e($p['stock']) ?></p>

                    <div class="card-actions">
                        <a href="index.php?edit=<?= e($p['id']) ?>" class="btn-edit">Edit</a>
                        
                        <!-- Form Delete via POST + CSRF -->
                        <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('Yakin ingin menghapus produk ini?');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= e($p['id']) ?>">
                            <button type="submit" class="btn-danger">Hapus</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>