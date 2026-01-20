<?php
session_start();
require_once 'config.php';

// 1. Security: Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// 2. Get the Order ID to track (from URL or latest active order)
if (isset($_GET['order_id'])) {
    $order_id = intval($_GET['order_id']);
} else {
    // Default to the most recent order if no ID provided
    $stmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $last_order = $result->fetch_assoc();
    $order_id = $last_order['id'] ?? 0;
}

// 3. Fetch Order Details
$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo "<script>alert('No active order found.'); window.location.href='dashboard.php';</script>";
    exit();
}

// 4. Fetch Spending Data for Analytics (Chart)
$analytics = $conn->prepare("SELECT cylinder_type, SUM(total_price) as total FROM orders WHERE user_id = ? GROUP BY cylinder_type");
$analytics->bind_param("i", $user_id);
$analytics->execute();
$res = $analytics->get_result();
$labels = []; 
$data = [];
while ($row = $res->fetch_assoc()) {
    $labels[] = ucfirst($row['cylinder_type']);
    $data[] = $row['total'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Track Order #<?php echo $order_id; ?></title>
    <link rel="stylesheet" href="dashboard.css">
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Specific styles for this page */
        .tracking-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px; }
        .card { background: white; padding: 20px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        #map { height: 400px; width: 100%; border-radius: 10px; z-index: 1; }
        
        .status-bar { display: flex; justify-content: space-between; background: white; padding: 20px; border-radius: 15px; margin-bottom: 20px; }
        .step { color: #ccc; font-weight: 500; position: relative; }
        .step.active { color: #ff9800; font-weight: bold; }
        .step.completed { color: #10b981; }
        
        @media (max-width: 900px) { .tracking-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo-section">
            <div class="logo">
                <span style="color:white; font-size:24px; font-weight:bold;">GasConnect</span>
            </div>
        </div>
        <div class="nav-section">
            <a href="dashboard.php" class="nav-item">← Back to Dashboard</a>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <h1>Tracking Order #<?php echo str_pad($order_id, 4, '0', STR_PAD_LEFT); ?></h1>
        </div>

        <div class="status-bar">
            <?php 
                $status = strtolower($order['order_status']);
                $steps = ['pending', 'confirmed', 'on the way', 'delivered'];
                $passed = true;
                foreach($steps as $step) {
                    $class = ($step == $status) ? "active" : ($passed ? "completed" : "");
                    if ($step == $status) $passed = false;
                    echo "<div class='step $class'>" . ucwords($step) . "</div>";
                }
            ?>
        </div>

        <div class="tracking-grid">
            <div class="card">
                <h2>📍 Live Delivery Map</h2>
                <div id="map"></div>
                <div style="margin-top:15px; display:flex; justify-content:space-between;">
                    <p><strong>Driver:</strong> John K. (Bike)</p>
                    <p><strong>ETA:</strong> <span id="eta">Calculating...</span></p>
                </div>
            </div>

            <div class="card">
                <h2>📊 Spending History</h2>
                <canvas id="spendingChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        // --- 1. MAP LOGIC (Leaflet.js) ---
        // Center on Nairobi
        var map = L.map('map').setView([-1.2921, 36.8219], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        var driverIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/758/758863.png',
            iconSize: [40, 40]
        });

        // Add Markers (Driver & You)
        // Note: In a real app, these coords would come from the database
        var driver = L.marker([-1.28, 36.815], {icon: driverIcon}).addTo(map).bindPopup("Driver");
        L.marker([-1.2921, 36.8219]).addTo(map).bindPopup("Your