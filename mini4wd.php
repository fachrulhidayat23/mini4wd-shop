<?php
/*
 * Mini 4WD Racing Store
 * Role under test : Customer
 * Fitur (8 total) : 1 Login, 2 Register, 3 Forgot Password,
 *                   4 Search & Filter Produk, 5 Keranjang (CRUD),
 *                   6 Checkout + Kode Promo (business rule),
 *                   7 Riwayat Pesanan (status change),
 *                   8 Form Kontak (validasi)
 */
session_start();

// ================= HELPER =================
define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) { mkdir(DATA_DIR, 0777, true); }

function load($f) {
    $p = DATA_DIR . "/$f.json";
    return file_exists($p) ? (json_decode(file_get_contents($p), true) ?: []) : [];
}
function save($f, $d) {
    file_put_contents(DATA_DIR . "/$f.json", json_encode($d, JSON_PRETTY_PRINT));
}
function e($s)      { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rupiah($n) { return 'Rp ' . number_format($n, 0, ',', '.'); }
function flash($type, $msg) { $_SESSION['flash'] = [$type, $msg]; }
function go($page)  { header("Location: ?page=$page"); exit; }
function validPassword($p) {
    return strlen($p) >= 8 && strlen($p) <= 32
        && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p);
}
function validQty($q, $stok) {
    // Aturan: bilangan bulat 1..10 dan tidak melebihi stok
    if (filter_var($q, FILTER_VALIDATE_INT) === false) return "Jumlah harus berupa bilangan bulat.";
    $q = (int)$q;
    if ($q < 1)     return "Jumlah minimal 1.";
    if ($q > 10)    return "Jumlah maksimal 10 per produk.";
    if ($q > $stok) return "Jumlah melebihi stok ($stok).";
    return null;
}

// ================= DATA PRODUK =================
$produk = [
    1 => ["id" => 1, "nama" => "Tamiya Mach Frame",  "harga" => 350000, "stok" => 10, "kategori" => "Kit"],
    2 => ["id" => 2, "nama" => "Tamiya Avante",      "harga" => 420000, "stok" => 5,  "kategori" => "Kit"],
    3 => ["id" => 3, "nama" => "Motor Hyper Dash",   "harga" => 85000,  "stok" => 20, "kategori" => "Sparepart"],
    4 => ["id" => 4, "nama" => "Roller Bearing Set", "harga" => 65000,  "stok" => 15, "kategori" => "Sparepart"],
    5 => ["id" => 5, "nama" => "Baterai Neo Champ",  "harga" => 40000,  "stok" => 30, "kategori" => "Aksesoris"],
];

// ================= ATURAN BISNIS (CHECKOUT) =================
// Kode promo MINI4WD10: diskon 10%, minimal belanja Rp300.000
// Ongkir Rp20.000, gratis jika subtotal >= Rp1.000.000
function hitungTotal($cart, $produk, $kode) {
    $subtotal = 0;
    foreach ($cart as $id => $qty) { $subtotal += $produk[$id]['harga'] * $qty; }
    $diskon = 0; $err = null;
    $kode = strtoupper(trim($kode));
    if ($kode !== '') {
        if ($kode !== 'MINI4WD10')      $err = "Kode promo tidak valid.";
        elseif ($subtotal < 300000)     $err = "Kode promo butuh minimal belanja Rp 300.000.";
        else                            $diskon = (int)($subtotal * 0.10);
    }
    $ongkir = ($subtotal >= 1000000 || $subtotal == 0) ? 0 : 20000;
    return [$subtotal, $diskon, $ongkir, $subtotal - $diskon + $ongkir, $err];
}

// ================= NAVIGASI =================
$allowed   = ['home','produk','kontak','login','register','lupa','reset','keranjang','checkout','pesanan'];
$page      = isset($_GET['page']) && in_array($_GET['page'], $allowed) ? $_GET['page'] : 'home';
$user      = $_SESSION['user'] ?? null;          // email user login
$protected = ['keranjang','checkout','pesanan'];
if (in_array($page, $protected) && !$user) {
    flash('error', 'Silakan login terlebih dahulu.');
    go('login');
}
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// ================= PROSES FORM (POST) =================
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------- FITUR 2: REGISTER ----------
    if ($action === 'register') {
        $nama    = trim($_POST['nama'] ?? '');
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $pass    = $_POST['password'] ?? '';
        $konfirm = $_POST['konfirmasi'] ?? '';
        $users   = load('users');

        if (mb_strlen($nama) < 3 || mb_strlen($nama) > 50) $errors[] = "Nama harus 3-50 karakter.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors[] = "Format email tidak valid.";
        if (!validPassword($pass))                         $errors[] = "Password 8-32 karakter dan harus mengandung huruf dan angka.";
        if ($pass !== $konfirm)                            $errors[] = "Konfirmasi password tidak cocok.";
        if (isset($users[$email]))                         $errors[] = "Email sudah terdaftar.";

        if (!$errors) {
            $users[$email] = ["nama" => $nama, "email" => $email,
                              "hash" => password_hash($pass, PASSWORD_DEFAULT),
                              "reset_token" => null, "reset_expires" => 0];
            save('users', $users);
            flash('success', 'Registrasi berhasil. Silakan login.');
            go('login');
        }
    }

    // ---------- FITUR 1: LOGIN ----------
    if ($action === 'login') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $users = load('users');

        if (($_SESSION['lock_until'] ?? 0) > time()) {
            $errors[] = "Terlalu banyak percobaan gagal. Coba lagi dalam " . ($_SESSION['lock_until'] - time()) . " detik.";
        } elseif ($email === '' || $pass === '') {
            $errors[] = "Email dan password wajib diisi.";
        } elseif (!isset($users[$email]) || !password_verify($pass, $users[$email]['hash'])) {
            $_SESSION['fail'] = ($_SESSION['fail'] ?? 0) + 1;
            if ($_SESSION['fail'] >= 3) {            // aturan bisnis: kunci 60 detik setelah 3x gagal
                $_SESSION['lock_until'] = time() + 60;
                $_SESSION['fail'] = 0;
                $errors[] = "Login gagal 3 kali. Akun dikunci 60 detik.";
            } else {
                $errors[] = "Email atau password salah.";
            }
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = $email;
            $_SESSION['fail'] = 0;
            flash('success', 'Selamat datang, ' . $users[$email]['nama'] . '!');
            go('home');
        }
    }

    // ---------- FITUR 3: FORGOT PASSWORD (langkah 1: minta token) ----------
    if ($action === 'lupa') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $users = load('users');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Format email tidak valid.";
        } else {
            if (isset($users[$email])) {
                $token = bin2hex(random_bytes(8));
                $users[$email]['reset_token']   = $token;
                $users[$email]['reset_expires'] = time() + 900;   // berlaku 15 menit
                save('users', $users);
                // MODE DEMO: tidak ada pengiriman email, link reset ditampilkan di layar
                $_SESSION['demo_reset_link'] = "?page=reset&token=$token";
            }
            // Pesan sama untuk email terdaftar / tidak terdaftar (mencegah user enumeration)
            flash('success', 'Jika email terdaftar, link reset password telah dikirim.');
            go('lupa');
        }
    }

    // ---------- FITUR 3: FORGOT PASSWORD (langkah 2: reset) ----------
    if ($action === 'reset') {
        $token = $_POST['token'] ?? '';
        $pass  = $_POST['password'] ?? '';
        $konf  = $_POST['konfirmasi'] ?? '';
        $users = load('users');
        $found = null;
        foreach ($users as $em => $u) {
            if ($token !== '' && $u['reset_token'] === $token && $u['reset_expires'] >= time()) { $found = $em; break; }
        }
        if (!$found)                $errors[] = "Token reset tidak valid atau sudah kedaluwarsa.";
        if (!validPassword($pass))  $errors[] = "Password 8-32 karakter dan harus mengandung huruf dan angka.";
        if ($pass !== $konf)        $errors[] = "Konfirmasi password tidak cocok.";
        if (!$errors) {
            $users[$found]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
            $users[$found]['reset_token'] = null;
            $users[$found]['reset_expires'] = 0;
            save('users', $users);
            flash('success', 'Password berhasil diubah. Silakan login.');
            go('login');
        }
    }

    // ---------- FITUR 5: KERANJANG (Create / Update / Delete) ----------
    if ($action === 'tambah_keranjang' && $user) {
        $id  = (int)($_POST['id'] ?? 0);
        $qty = $_POST['qty'] ?? '';
        if (!isset($produk[$id])) {
            flash('error', 'Produk tidak ditemukan.');
        } else {
            $err = validQty($qty, $produk[$id]['stok']);
            $total = ($_SESSION['cart'][$id] ?? 0) + (int)$qty;
            if (!$err && $total > 10) $err = "Total produk ini di keranjang maksimal 10.";
            if (!$err && $total > $produk[$id]['stok']) $err = "Total melebihi stok.";
            if ($err) { flash('error', $err); }
            else { $_SESSION['cart'][$id] = $total; flash('success', $produk[$id]['nama'] . ' ditambahkan ke keranjang.'); }
        }
        go('produk');
    }
    if ($action === 'ubah_keranjang' && $user) {
        $id  = (int)($_POST['id'] ?? 0);
        $qty = $_POST['qty'] ?? '';
        if (isset($_SESSION['cart'][$id])) {
            $err = validQty($qty, $produk[$id]['stok']);
            if ($err) flash('error', $err);
            else { $_SESSION['cart'][$id] = (int)$qty; flash('success', 'Jumlah diperbarui.'); }
        }
        go('keranjang');
    }
    if ($action === 'hapus_keranjang' && $user) {
        unset($_SESSION['cart'][(int)($_POST['id'] ?? 0)]);
        flash('success', 'Item dihapus dari keranjang.');
        go('keranjang');
    }

    // ---------- FITUR 6: CHECKOUT + KODE PROMO ----------
    if ($action === 'checkout' && $user) {
        $alamat = trim($_POST['alamat'] ?? '');
        $kode   = $_POST['kode'] ?? '';
        if (!$_SESSION['cart'])                                   $errors[] = "Keranjang masih kosong.";
        if (mb_strlen($alamat) < 10 || mb_strlen($alamat) > 200)  $errors[] = "Alamat harus 10-200 karakter.";
        [$sub, $diskon, $ongkir, $total, $promoErr] = hitungTotal($_SESSION['cart'], $produk, $kode);
        if ($promoErr) $errors[] = $promoErr;

        if (!$errors) {
            $items = [];
            foreach ($_SESSION['cart'] as $id => $qty) {
                $items[] = ["nama" => $produk[$id]['nama'], "harga" => $produk[$id]['harga'], "qty" => $qty];
            }
            $orders = load('orders');
            $oid = 'ORD-' . date('ymd') . '-' . random_int(1000, 9999);
            $orders[$oid] = ["id" => $oid, "email" => $user, "items" => $items,
                             "subtotal" => $sub, "diskon" => $diskon, "ongkir" => $ongkir,
                             "total" => $total, "alamat" => $alamat,
                             "kode" => strtoupper(trim($kode)),
                             "status" => "Menunggu Pembayaran", "dibuat" => date('Y-m-d H:i:s')];
            save('orders', $orders);
            $_SESSION['cart'] = [];
            flash('success', "Pesanan $oid berhasil dibuat.");
            go('pesanan');
        }
    }

    // ---------- FITUR 7: STATUS PESANAN ----------
    // Menunggu Pembayaran -> Dibayar -> Dikirim -> Selesai   (atau Menunggu Pembayaran -> Dibatalkan)
    if (in_array($action, ['bayar','batal','kirim_sim','terima']) && $user) {
        $orders = load('orders');
        $oid = $_POST['oid'] ?? '';
        if (!isset($orders[$oid]) || $orders[$oid]['email'] !== $user) {
            flash('error', 'Pesanan tidak ditemukan.');
        } else {
            $st = $orders[$oid]['status'];
            $transisi = [
                'bayar'     => ['Menunggu Pembayaran', 'Dibayar'],
                'batal'     => ['Menunggu Pembayaran', 'Dibatalkan'],
                'kirim_sim' => ['Dibayar',             'Dikirim'],      // simulasi aksi admin (khusus demo)
                'terima'    => ['Dikirim',             'Selesai'],
            ];
            [$dari, $ke] = $transisi[$action];
            if ($st !== $dari) {
                flash('error', "Aksi tidak diizinkan pada status \"$st\".");
            } else {
                $orders[$oid]['status'] = $ke;
                save('orders', $orders);
                flash('success', "Status pesanan menjadi \"$ke\".");
            }
        }
        go('pesanan');
    }

    // ---------- FITUR 8: FORM KONTAK (validasi) ----------
    if ($action === 'kontak') {
        $nama  = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pesan = trim($_POST['pesan'] ?? '');
        if (mb_strlen($nama) < 3 || mb_strlen($nama) > 50)    $errors[] = "Nama harus 3-50 karakter.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))       $errors[] = "Format email tidak valid.";
        if (mb_strlen($pesan) < 10 || mb_strlen($pesan) > 500) $errors[] = "Pesan harus 10-500 karakter.";
        if (!$errors) {
            $m = load('pesan');
            $m[] = ["nama" => $nama, "email" => $email, "pesan" => $pesan, "waktu" => date('c')];
            save('pesan', $m);
            flash('success', 'Terima kasih, pesan Anda telah dikirim!');
            go('kontak');
        }
    }

    if ($action === 'logout') {
        session_destroy();
        session_start();
        flash('success', 'Anda telah logout.');
        go('home');
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$cartCount = array_sum($_SESSION['cart']);
$userData  = $user ? (load('users')[$user] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mini 4WD Racing Store</title>

<!-- CSS -->
<style>
    body { font-family: Arial; margin: 0; background: #f2f2f2; }
    header { background: #111; color: white; padding: 15px; }
    header h1 { margin: 0 0 10px; }
    nav a, nav button.link { color: white; margin-right: 15px; text-decoration: none; background: none; border: none; padding: 0; font-size: 1em; cursor: pointer; }
    nav form { display: inline; }
    .hero { background: crimson; color: white; text-align: center; padding: 40px; }
    .wrap { padding: 20px; }
    .produk { display: flex; gap: 20px; flex-wrap: wrap; padding: 20px; }
    .card { background: white; padding: 15px; width: 200px; border-radius: 8px; text-align: center; }
    .box  { background: white; padding: 20px; border-radius: 8px; max-width: 420px; }
    .wide { max-width: 760px; }
    button { background: black; color: white; border: none; padding: 8px 12px; cursor: pointer; }
    button.danger { background: crimson; }
    input, textarea, select { display: block; width: 100%; box-sizing: border-box; margin-bottom: 10px; padding: 8px; }
    .inline input, .inline select { display: inline-block; width: auto; margin: 0 5px 0 0; }
    .card input[type=text] { width: 70px; display: inline-block; }
    .notif { padding: 12px 20px; margin: 15px 20px 0; border-radius: 6px; }
    .notif.success { background: #e6f6e6; color: #1b6b1b; }
    .notif.error   { background: #fde8e8; color: #a11; }
    .errors { background: #fde8e8; color: #a11; padding: 10px 10px 10px 28px; margin-bottom: 12px; border-radius: 6px; }
    table { border-collapse: collapse; width: 100%; background: white; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .badge { padding: 3px 8px; border-radius: 10px; background: #ddd; font-size: 0.85em; }
    .muted { color: #666; font-size: 0.9em; }
</style>

<!-- JQUERY -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- JAVASCRIPT -->
<script>
    $(document).ready(function () {
        $('#btnPromo').click(function () {
            alert('Promo minggu ini: Diskon 10% dengan kode MINI4WD10 (minimal belanja Rp 300.000)!');
        });
    });
</script>
</head>
<body>

<header>
    <h1>Mini 4WD Racing Store</h1>
    <nav>
        <a href="?page=home">Home</a>
        <a href="?page=produk">Produk</a>
        <a href="?page=kontak">Kontak</a>
        <?php if ($user): ?>
            <a href="?page=keranjang" id="nav-keranjang">Keranjang (<?= $cartCount ?>)</a>
            <a href="?page=pesanan">Pesanan</a>
            <span>Hai, <?= e($userData['nama'] ?? '') ?></span>
            <form method="post"><input type="hidden" name="action" value="logout"><button type="submit" class="link" id="btnLogout">Logout</button></form>
        <?php else: ?>
            <a href="?page=login" id="nav-login">Login</a>
            <a href="?page=register" id="nav-register">Register</a>
        <?php endif; ?>
    </nav>
</header>

<?php if ($flash): ?>
    <div class="notif <?= e($flash[0]) ?>" id="flash"><?= e($flash[1]) ?></div>
<?php endif; ?>

<?php
// Komponen kecil untuk menampilkan daftar error
function showErrors($errors) {
    if (!$errors) return;
    echo '<ul class="errors" id="errors">';
    foreach ($errors as $er) echo '<li>' . e($er) . '</li>';
    echo '</ul>';
}
?>

<!-- ================= HOME ================= -->
<?php if ($page == 'home'): ?>
    <section class="hero">
        <h2>Surga Pecinta Mini 4WD</h2>
        <p>Menjual kit, sparepart, dan aksesoris Mini 4WD original.</p>
        <button id="btnPromo">Lihat Promo</button>
    </section>
    <section style="padding:20px">
        <h3>Kenapa Pilih Kami?</h3>
        <ul>
            <li>Produk original</li>
            <li>Harga bersahabat</li>
            <li>Cocok untuk pemula &amp; pro racer</li>
        </ul>
    </section>

<!-- ================= REGISTER ================= -->
<?php elseif ($page == 'register'): ?>
    <div class="wrap"><div class="box">
        <h2>Register</h2>
        <?php showErrors($errors); ?>
        <form method="post" novalidate>
            <input type="hidden" name="action" value="register">
            <input type="text"     id="reg-nama"       name="nama"       placeholder="Nama lengkap" value="<?= e($_POST['nama'] ?? '') ?>">
            <input type="text"     id="reg-email"      name="email"      placeholder="Email" value="<?= e($_POST['email'] ?? '') ?>">
            <input type="password" id="reg-password"   name="password"   placeholder="Password (8-32, huruf + angka)">
            <input type="password" id="reg-konfirmasi" name="konfirmasi" placeholder="Konfirmasi password">
            <button type="submit" id="btnRegister">Daftar</button>
        </form>
        <p class="muted">Sudah punya akun? <a href="?page=login">Login</a></p>
    </div></div>

<!-- ================= LOGIN ================= -->
<?php elseif ($page == 'login'): ?>
    <div class="wrap"><div class="box">
        <h2>Login</h2>
        <?php showErrors($errors); ?>
        <form method="post" novalidate>
            <input type="hidden" name="action" value="login">
            <input type="text"     id="login-email"    name="email"    placeholder="Email" value="<?= e($_POST['email'] ?? '') ?>">
            <input type="password" id="login-password" name="password" placeholder="Password">
            <button type="submit" id="btnLogin">Login</button>
        </form>
        <p class="muted"><a href="?page=lupa">Lupa password?</a> | <a href="?page=register">Buat akun</a></p>
    </div></div>

<!-- ================= LUPA PASSWORD ================= -->
<?php elseif ($page == 'lupa'): ?>
    <div class="wrap"><div class="box">
        <h2>Lupa Password</h2>
        <?php showErrors($errors); ?>
        <form method="post" novalidate>
            <input type="hidden" name="action" value="lupa">
            <input type="text" id="lupa-email" name="email" placeholder="Email terdaftar">
            <button type="submit" id="btnLupa">Kirim Link Reset</button>
        </form>
        <?php if (!empty($_SESSION['demo_reset_link'])): ?>
            <p class="muted" id="demo-link">[MODE DEMO - pengganti email] <a href="<?= e($_SESSION['demo_reset_link']) ?>">Buka link reset</a></p>
            <?php unset($_SESSION['demo_reset_link']); ?>
        <?php endif; ?>
    </div></div>

<!-- ================= RESET PASSWORD ================= -->
<?php elseif ($page == 'reset'): ?>
    <div class="wrap"><div class="box">
        <h2>Reset Password</h2>
        <?php showErrors($errors); ?>
        <form method="post" novalidate>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= e($_GET['token'] ?? ($_POST['token'] ?? '')) ?>">
            <input type="password" id="reset-password"   name="password"   placeholder="Password baru (8-32, huruf + angka)">
            <input type="password" id="reset-konfirmasi" name="konfirmasi" placeholder="Konfirmasi password baru">
            <button type="submit" id="btnReset">Simpan Password</button>
        </form>
    </div></div>

<!-- ================= PRODUK (Search & Filter) ================= -->
<?php elseif ($page == 'produk'):
    $q   = trim($_GET['q'] ?? '');
    $kat = $_GET['kategori'] ?? '';
    $max = $_GET['harga_max'] ?? '';
    $hasil = array_filter($produk, function ($p) use ($q, $kat, $max) {
        if ($q !== '' && stripos($p['nama'], $q) === false) return false;
        if ($kat !== '' && $p['kategori'] !== $kat) return false;
        if ($max !== '' && is_numeric($max) && $p['harga'] > (float)$max) return false;
        return true;
    });
?>
    <div class="wrap">
        <form method="get" class="inline" novalidate>
            <input type="hidden" name="page" value="produk">
            <input type="text" id="cari" name="q" placeholder="Cari produk..." value="<?= e($q) ?>">
            <select id="filter-kategori" name="kategori">
                <option value="">Semua kategori</option>
                <?php foreach (['Kit','Sparepart','Aksesoris'] as $k): ?>
                    <option value="<?= $k ?>" <?= $kat === $k ? 'selected' : '' ?>><?= $k ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="filter-harga" name="harga_max" placeholder="Harga maks" value="<?= e($max) ?>">
            <button type="submit" id="btnCari">Cari</button>
        </form>
        <?php if ($max !== '' && !is_numeric($max)): ?><p class="muted">Harga maksimal harus berupa angka; filter harga diabaikan.</p><?php endif; ?>
    </div>
    <section class="produk">
        <?php foreach ($hasil as $p): ?>
            <div class="card">
                <h3><?= e($p['nama']); ?></h3>
                <p class="muted"><?= e($p['kategori']) ?> &middot; stok <?= $p['stok'] ?></p>
                <p><?= rupiah($p['harga']); ?></p>
                <form method="post" novalidate>
                    <input type="hidden" name="action" value="tambah_keranjang">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <input type="text" name="qty" value="1" id="qty-<?= $p['id'] ?>">
                    <button type="submit" id="beli-<?= $p['id'] ?>">Beli</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if (!$hasil): ?><p id="kosong">Produk tidak ditemukan.</p><?php endif; ?>
    </section>
    <?php if (!$user): ?><p class="muted" style="padding:0 20px">Login terlebih dahulu untuk menambahkan produk ke keranjang.</p><?php endif; ?>

<!-- ================= KERANJANG ================= -->
<?php elseif ($page == 'keranjang'): ?>
    <div class="wrap"><div class="box wide">
        <h2>Keranjang</h2>
        <?php if (!$_SESSION['cart']): ?>
            <p id="cart-kosong">Keranjang kosong. <a href="?page=produk">Belanja dulu</a></p>
        <?php else: ?>
            <table>
                <tr><th>Produk</th><th>Harga</th><th>Jumlah</th><th>Subtotal</th><th>Aksi</th></tr>
                <?php foreach ($_SESSION['cart'] as $id => $qty): ?>
                <tr>
                    <td><?= e($produk[$id]['nama']) ?></td>
                    <td><?= rupiah($produk[$id]['harga']) ?></td>
                    <td>
                        <form method="post" class="inline" novalidate>
                            <input type="hidden" name="action" value="ubah_keranjang">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="text" name="qty" value="<?= $qty ?>" size="3" id="cart-qty-<?= $id ?>">
                            <button type="submit">Ubah</button>
                        </form>
                    </td>
                    <td><?= rupiah($produk[$id]['harga'] * $qty) ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="action" value="hapus_keranjang">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="danger">Hapus</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <p><a href="?page=checkout"><button id="btnKeCheckout">Lanjut ke Checkout</button></a></p>
        <?php endif; ?>
    </div></div>

<!-- ================= CHECKOUT ================= -->
<?php elseif ($page == 'checkout'):
    $kode = $_POST['kode'] ?? '';
    [$sub, $diskon, $ongkir, $total, $promoErr] = hitungTotal($_SESSION['cart'], $produk, $kode);
?>
    <div class="wrap"><div class="box wide">
        <h2>Checkout</h2>
        <?php if (!$_SESSION['cart']): ?>
            <p>Keranjang kosong. <a href="?page=produk">Belanja dulu</a></p>
        <?php else: ?>
            <?php showErrors($errors); ?>
            <table>
                <?php foreach ($_SESSION['cart'] as $id => $qty): ?>
                    <tr><td><?= e($produk[$id]['nama']) ?> &times; <?= $qty ?></td><td><?= rupiah($produk[$id]['harga'] * $qty) ?></td></tr>
                <?php endforeach; ?>
                <tr><td>Subtotal</td><td id="co-subtotal"><?= rupiah($sub) ?></td></tr>
                <tr><td>Diskon</td><td id="co-diskon">- <?= rupiah($diskon) ?></td></tr>
                <tr><td>Ongkir</td><td id="co-ongkir"><?= rupiah($ongkir) ?></td></tr>
                <tr><th>Total</th><th id="co-total"><?= rupiah($total) ?></th></tr>
            </table>
            <p class="muted">Aturan: kode MINI4WD10 = diskon 10% (min. belanja Rp 300.000). Ongkir Rp 20.000, gratis jika subtotal &ge; Rp 1.000.000.</p>
            <form method="post" novalidate>
                <input type="hidden" name="action" value="checkout">
                <textarea id="co-alamat" name="alamat" placeholder="Alamat pengiriman (10-200 karakter)"><?= e($_POST['alamat'] ?? '') ?></textarea>
                <input type="text" id="co-kode" name="kode" placeholder="Kode promo (opsional)" value="<?= e($kode) ?>">
                <button type="submit" id="btnCheckout">Buat Pesanan</button>
            </form>
        <?php endif; ?>
    </div></div>

<!-- ================= PESANAN (status change) ================= -->
<?php elseif ($page == 'pesanan'):
    $mine = array_filter(load('orders'), fn($o) => $o['email'] === $user);
    $mine = array_reverse($mine);
?>
    <div class="wrap"><div class="box wide">
        <h2>Riwayat Pesanan</h2>
        <?php if (!$mine): ?>
            <p id="pesanan-kosong">Belum ada pesanan.</p>
        <?php endif; ?>
        <?php foreach ($mine as $o): ?>
            <div style="border:1px solid #ddd; padding:12px; margin-bottom:12px; border-radius:6px;">
                <strong><?= e($o['id']) ?></strong>
                <span class="badge" id="status-<?= e($o['id']) ?>"><?= e($o['status']) ?></span>
                <div class="muted"><?= e($o['dibuat']) ?> &middot; <?= e($o['alamat']) ?></div>
                <ul>
                    <?php foreach ($o['items'] as $it): ?>
                        <li><?= e($it['nama']) ?> &times; <?= $it['qty'] ?> (<?= rupiah($it['harga'] * $it['qty']) ?>)</li>
                    <?php endforeach; ?>
                </ul>
                <p>Total: <strong><?= rupiah($o['total']) ?></strong>
                    <?= $o['kode'] ? '(promo ' . e($o['kode']) . ')' : '' ?></p>
                <form method="post" class="inline">
                    <input type="hidden" name="oid" value="<?= e($o['id']) ?>">
                    <?php if ($o['status'] === 'Menunggu Pembayaran'): ?>
                        <button name="action" value="bayar">Konfirmasi Pembayaran</button>
                        <button name="action" value="batal" class="danger">Batalkan</button>
                    <?php elseif ($o['status'] === 'Dibayar'): ?>
                        <button name="action" value="kirim_sim">(Demo) Simulasikan Dikirim</button>
                    <?php elseif ($o['status'] === 'Dikirim'): ?>
                        <button name="action" value="terima">Pesanan Diterima</button>
                    <?php endif; ?>
                </form>
            </div>
        <?php endforeach; ?>
    </div></div>

<!-- ================= KONTAK ================= -->
<?php elseif ($page == 'kontak'): ?>
    <div class="wrap"><div class="box">
        <h2>Hubungi Kami</h2>
        <?php showErrors($errors); ?>
        <form method="post" class="form-kontak" novalidate>
            <input type="hidden" name="action" value="kontak">
            <input type="text"  id="kontak-nama"  name="nama"  placeholder="Nama" value="<?= e($_POST['nama'] ?? '') ?>">
            <input type="text"  id="kontak-email" name="email" placeholder="Email" value="<?= e($_POST['email'] ?? '') ?>">
            <textarea id="kontak-pesan" name="pesan" placeholder="Pesan (10-500 karakter)"><?= e($_POST['pesan'] ?? '') ?></textarea>
            <button type="submit" id="btnKirim">Kirim</button>
        </form>
    </div></div>
<?php endif; ?>

<footer style="text-align:center; padding:15px;">
    <p>&copy; 2026 Mini 4WD Racing Store</p>
</footer>
</body>
</html>