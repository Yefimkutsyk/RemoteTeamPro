<?php
// company_profile.php
require_once __DIR__ . "/../config/database.php";

$database = new Database();
$pdo = $database->getConnection();
// Get company ID from URL
$companyId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($companyId <= 0) {
    die("Invalid company ID.");
}

// Fetch company details
try {
    $stmt = $pdo->prepare("SELECT * FROM Companies WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        die("Company not found.");
    }
} catch (Exception $e) {
    die("Error fetching company: " . $e->getMessage());
}

// Fetch company members
try {
    $stmt_users = $pdo->prepare("SELECT user_id, first_name, last_name, role, profile_picture_url FROM Users WHERE company_id = ? ORDER BY role, first_name");
    $stmt_users->execute([$companyId]);
    $members = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $members = [];
    $members_error = "Error fetching team members: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($company['company_name']) ?> - Company Profile</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-900 text-gray-200 font-sans">

  <!-- Navbar -->
  <nav class="bg-gray-950 bg-opacity-90 fixed w-full z-50 top-0 shadow-md">
    <div class="container mx-auto px-6 py-4 flex justify-between items-center">
      <a href="/RemoteTeamPro/frontend/src/pages/index.html" class="text-2xl font-bold text-purple-400">RemoteTeamPro</a>
      <ul class="hidden md:flex space-x-8 text-gray-300">
        <li><a href="/RemoteTeamPro/frontend/src/pages/index.html#hero" class="hover:text-purple-400">Home</a></li>
        <li><a href="/RemoteTeamPro/frontend/src/pages/index.html#features" class="hover:text-purple-400">Features</a></li>
        <li><a href="/RemoteTeamPro/frontend/src/pages/index.html#companies" class="hover:text-purple-400">Companies</a></li>
        <li><a href="/RemoteTeamPro/frontend/src/pages/index.html#about-us" class="hover:text-purple-400">About</a></li>
        <li><a href="/RemoteTeamPro/frontend/src/pages/index.html#contact" class="hover:text-purple-400">Contact</a></li>
      </ul>
      <a href="/RemoteTeamPro/frontend/src/pages/login.html" class="px-4 py-2 bg-purple-600 rounded-lg text-white font-semibold shadow hover:shadow-lg hover:scale-105 transition-transform duration-200">Login</a>
    </div>
  </nav>

  <!-- Company Profile Section -->
  <div class="container mx-auto px-6 py-32">
    <div class="bg-gray-800/70 backdrop-blur-md rounded-2xl shadow-lg border border-gray-700 p-8">
      <!-- Company Name -->
      <h1 class="text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-500 mb-6">
        <?= htmlspecialchars($company['company_name']) ?>
      </h1>

      <!-- Services -->
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-white mb-2">Services</h2>
        <p class="text-gray-300"><?= $company['services'] ? nl2br(htmlspecialchars($company['services'])) : "No services listed." ?></p>
      </div>

      <!-- Team Members -->
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-white mb-4">Team Members</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
          <?php if (!empty($members)): ?>
            <?php foreach ($members as $member): ?>
              <div class="text-center">
                <img src="<?= htmlspecialchars($member['profile_picture_url'] ?: '../../frontend/assets/images/index/default-profile.png') ?>" alt="<?= htmlspecialchars($member['first_name']) ?>" class="w-20 h-20 rounded-full object-cover mx-auto mb-2 border-2 border-purple-400">
                <h3 class="font-semibold text-white"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h3>
                <p class="text-sm text-gray-400"><?= htmlspecialchars($member['role']) ?></p>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="text-gray-400 col-span-full"><?= isset($members_error) ? htmlspecialchars($members_error) : "No team members found for this company." ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Meta -->
      <div class="mt-8 text-sm text-gray-400">
        <p><strong>Company ID:</strong> <?= $company['company_id'] ?></p>
        <p><strong>Registered on:</strong> <?= $company['created_at'] ?></p>
      </div>

      <!-- Back Button -->
      <div class="mt-8 flex flex-wrap gap-4">
        <a href="/RemoteTeamPro/frontend/src/pages/index.html#companies" class="px-6 py-2 bg-gradient-to-r from-purple-600 to-pink-600 rounded-lg text-white font-semibold shadow-md hover:shadow-lg hover:scale-105 transition-transform duration-200">
          ← Back to Companies
        </a>
        <a href="/RemoteTeamPro/frontend/src/pages/index.html?company_id=<?= $company['company_id'] ?>&company_name=<?= urlencode($company['company_name']) ?>#contact" class="px-6 py-2 bg-gradient-to-r from-green-500 to-teal-500 rounded-lg text-white font-semibold shadow-md hover:shadow-lg hover:scale-105 transition-transform duration-200">
          Contact this Company <i class="fas fa-arrow-right ml-2"></i>
        </a>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer class="bg-gray-950 py-6 text-center text-gray-400 mt-12 border-t border-gray-800">
    <p>&copy; <?= date("Y") ?> RemoteTeamPro. All rights reserved.</p>
  </footer>

</body>
</html>
