<?php
/**
 * PROFESSIONAL MEAL TRACKER
 * 
 * FEATURES:
 * - User profile with BMI.
 * - Daily total calories shown in UI.
 * - Macronutrients (fats, protein, carbs) fetched from CalorieNinjas.
 * - Compare daily calories to estimated maintenance (weight*24).
 * - **Fixed "Get Nutrition" button** – shows each item's macros in a list.
 */

// ==================== CONFIGURATION ====================
define('DB_HOST', 'sql305.infinityfree.com');   // Your MySQL server
define('DB_USER', 'if0_41269202');              // Your database username
define('DB_PASS', 'znBzIiUBEYjr3');             // Your database password
define('DB_NAME', 'if0_41269202_mealtracker');        // Your database name

// CalorieNinjas API Key (get free from https://calorieninjas.com/)
define('CALORIE_NINJAS_API_KEY', 'n7sIJqzWSMa2QoiveriAvQ==0EaeqRbrjagkWgP2');
// ========================================================

// Connect to database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}
$conn->set_charset('utf8');

// ---------- API ROUTING ----------
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action) {
    header('Content-Type: application/json');

    // GET requests
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($action) {
            case 'get_calories':
                // ========== UPDATED: return full items list ==========
                if (empty($_GET['food'])) {
                    echo json_encode(['error' => 'Please enter a food description.']);
                    exit;
                }
                $food = urlencode(trim($_GET['food']));
                $api_url = "https://api.calorieninjas.com/v1/nutrition?query={$food}";

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Api-Key: ' . CALORIE_NINJAS_API_KEY]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);

                if ($http_code !== 200) {
                    if ($curl_error) {
                        echo json_encode(['error' => 'Connection error: ' . $curl_error]);
                    } else {
                        echo json_encode(['error' => 'Calorie service unavailable (HTTP ' . $http_code . ')']);
                    }
                    exit;
                }

                $data = json_decode($response, true);
                
                if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
                    echo json_encode(['error' => 'No match found. Try simpler terms.']);
                    exit;
                }

                // Return the full items array so JS can display each one
                echo json_encode([
                    'items' => $data['items']
                ]);
                exit;

            case 'get_meals':
                $sql = "SELECT * FROM meals ORDER BY date DESC, id DESC";
                $result = $conn->query($sql);
                $meals = [];
                while ($row = $result->fetch_assoc()) {
                    $meals[] = $row;
                }
                // Group by date
                $grouped = [];
                foreach ($meals as $meal) {
                    $grouped[$meal['date']][] = $meal;
                }
                echo json_encode($grouped);
                exit;

            case 'get_profile':
                $result = $conn->query("SELECT * FROM user_profile WHERE id = 1");
                if ($result->num_rows == 0) {
                    // Insert default profile
                    $conn->query("INSERT INTO user_profile (id) VALUES (1)");
                    $profile = ['id' => 1, 'name' => null, 'weight_kg' => null, 'height_cm' => null, 'age' => null, 'gender' => null];
                } else {
                    $profile = $result->fetch_assoc();
                }
                // Calculate BMI if possible
                if ($profile['weight_kg'] && $profile['height_cm'] && $profile['height_cm'] > 0) {
                    $height_m = $profile['height_cm'] / 100;
                    $bmi = $profile['weight_kg'] / ($height_m * $height_m);
                    $profile['bmi'] = round($bmi, 1);
                    // Category
                    if ($bmi < 18.5) $profile['bmi_category'] = 'Underweight';
                    elseif ($bmi < 25) $profile['bmi_category'] = 'Normal weight';
                    elseif ($bmi < 30) $profile['bmi_category'] = 'Overweight';
                    else $profile['bmi_category'] = 'Obese';
                } else {
                    $profile['bmi'] = null;
                    $profile['bmi_category'] = null;
                }
                echo json_encode($profile);
                exit;

            default:
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }
    }

    // POST requests (unchanged, but keep them)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }

        switch ($action) {
            case 'add_meal':
                $stmt = $conn->prepare("INSERT INTO meals (date, meal_type, food_description, calories, fat_g, protein_g, carbs_g) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $calories = isset($input['calories']) && is_numeric($input['calories']) ? intval($input['calories']) : null;
                $fat      = isset($input['fat'])      && is_numeric($input['fat'])      ? floatval($input['fat']) : null;
                $protein  = isset($input['protein'])  && is_numeric($input['protein'])  ? floatval($input['protein']) : null;
                $carbs    = isset($input['carbs'])    && is_numeric($input['carbs'])    ? floatval($input['carbs']) : null;
                $stmt->bind_param("sssiddd", $input['date'], $input['meal_type'], $input['food_description'], $calories, $fat, $protein, $carbs);
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
                } else {
                    echo json_encode(['success' => false, 'error' => $stmt->error]);
                }
                $stmt->close();
                exit;

            case 'update_meal':
                $stmt = $conn->prepare("UPDATE meals SET date=?, meal_type=?, food_description=?, calories=?, fat_g=?, protein_g=?, carbs_g=? WHERE id=?");
                $calories = isset($input['calories']) && is_numeric($input['calories']) ? intval($input['calories']) : null;
                $fat      = isset($input['fat'])      && is_numeric($input['fat'])      ? floatval($input['fat']) : null;
                $protein  = isset($input['protein'])  && is_numeric($input['protein'])  ? floatval($input['protein']) : null;
                $carbs    = isset($input['carbs'])    && is_numeric($input['carbs'])    ? floatval($input['carbs']) : null;
                $stmt->bind_param("sssidddi", $input['date'], $input['meal_type'], $input['food_description'], $calories, $fat, $protein, $carbs, $input['id']);
                if ($stmt->execute()) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => $stmt->error]);
                }
                $stmt->close();
                exit;

            case 'delete_meal':
                $stmt = $conn->prepare("DELETE FROM meals WHERE id=?");
                $stmt->bind_param("i", $input['id']);
                if ($stmt->execute()) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => $stmt->error]);
                }
                $stmt->close();
                exit;

            case 'update_profile':
                $stmt = $conn->prepare("UPDATE user_profile SET name=?, weight_kg=?, height_cm=?, age=?, gender=? WHERE id=1");
                $name   = $input['name'] ?? null;
                $weight = isset($input['weight_kg']) && is_numeric($input['weight_kg']) ? floatval($input['weight_kg']) : null;
                $height = isset($input['height_cm']) && is_numeric($input['height_cm']) ? floatval($input['height_cm']) : null;
                $age    = isset($input['age'])       && is_numeric($input['age'])       ? intval($input['age']) : null;
                $gender = $input['gender'] ?? null;
                $stmt->bind_param("sddds", $name, $weight, $height, $age, $gender);
                if ($stmt->execute()) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => $stmt->error]);
                }
                $stmt->close();
                exit;

            default:
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }
    }

    echo json_encode(['error' => 'Unsupported request method']);
    exit;
}

// ---------- HTML + JavaScript (UI) ----------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pro Meal Tracker</title>
    <!-- Bootstrap 5 + Font Awesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f7fb; font-family: 'Segoe UI', Roboto, sans-serif; }
        .profile-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .meal-card { border: none; border-radius: 16px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.03); transition: all 0.2s; }
        .meal-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .badge-macro { background: #e9f0f9; color: #1e3c5c; font-weight: 500; padding: 6px 12px; border-radius: 30px; font-size: 0.8rem; }
        .total-badge { background: #2c3e50; color: white; font-size: 1rem; padding: 8px 18px; border-radius: 40px; }
        .food-item-list { background: #f8fafd; border-radius: 12px; padding: 12px; margin-top: 12px; border-left: 4px solid #20c997; }
        .food-item-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px dashed #dee2e6; }
        .food-item-row:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="display-6 fw-bold text-dark">🍽️ Daily Meal Tracker</h1>
            <button class="btn btn-outline-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="fas fa-user me-2"></i>My Profile
            </button>
        </div>

        <!-- Profile Summary Card -->
        <div class="profile-card p-4 mb-5" id="profileSummary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-3"><i class="fas fa-address-card me-2 text-primary"></i>Profile Summary</h5>
                    <div id="profileDetails">Loading profile...</div>
                </div>
                <div class="col-md-6 text-md-end">
                    <button class="btn btn-sm btn-outline-secondary rounded-pill" data-bs-toggle="modal" data-bs-target="#profileModal">
                        <i class="fas fa-edit me-1"></i>Edit
                    </button>
                </div>
            </div>
        </div>

        <!-- Add Meal Form -->
        <div class="profile-card p-4 mb-5">
            <h5 class="mb-3"><i class="fas fa-plus-circle me-2 text-success"></i>Add New Meal</h5>
            <form id="mealForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control rounded-pill" id="date" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Meal Type</label>
                        <select class="form-select rounded-pill" id="mealType" required>
                            <option value="Breakfast">Breakfast</option>
                            <option value="Lunch">Lunch</option>
                            <option value="Dinner">Dinner</option>
                            <option value="Snack">Snack</option>
                            <option value="Brunch">Brunch</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Food Description</label>
                        <div class="input-group">
                            <input type="text" class="form-control rounded-pill" id="foodDescription" placeholder="e.g., 2 eggs, 1 cup rice" required>
                            <button class="btn btn-outline-success rounded-pill" type="button" id="fetchCaloriesBtn">
                                <i class="fas fa-bolt"></i> Get Nutrition
                            </button>
                        </div>
                        <!-- Area for displaying individual food items -->
                        <div id="nutritionDetails" class="mt-2"></div>
                        <div id="calorieResult" class="form-text text-muted small mt-2"></div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary rounded-pill px-5 py-2">
                            <i class="fas fa-save me-2"></i>Add Meal
                        </button>
                    </div>
                </div>
                <input type="hidden" id="caloriesField" value="">
                <input type="hidden" id="fatField" value="">
                <input type="hidden" id="proteinField" value="">
                <input type="hidden" id="carbsField" value="">
            </form>
            <div class="form-text text-muted mt-3">
                💡 Tip: Include quantities (e.g., "2 eggs", "100g chicken"). Click "Get Nutrition" to see each item's macros.
            </div>
        </div>

        <!-- Meals List -->
        <div id="mealsContainer"></div>
    </div>

    <!-- Profile Modal (unchanged) -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Your Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="profileForm">
                        <div class="mb-3">
                            <label class="form-label">Name (optional)</label>
                            <input type="text" class="form-control" id="profileName" placeholder="Your name">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Weight (kg)</label>
                                <input type="number" step="0.1" class="form-control" id="profileWeight" placeholder="e.g., 70">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Height (cm)</label>
                                <input type="number" step="0.1" class="form-control" id="profileHeight" placeholder="e.g., 175">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Age (optional)</label>
                                <input type="number" class="form-control" id="profileAge" placeholder="e.g., 30">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gender (optional)</label>
                                <select class="form-select" id="profileGender">
                                    <option value="">Prefer not to say</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveProfileBtn">Save Profile</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Meal Modal (unchanged) -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Meal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editForm">
                        <input type="hidden" id="editId">
                        <div class="mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" id="editDate" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Meal Type</label>
                            <select class="form-select" id="editMealType" required>
                                <option value="Breakfast">Breakfast</option>
                                <option value="Lunch">Lunch</option>
                                <option value="Dinner">Dinner</option>
                                <option value="Snack">Snack</option>
                                <option value="Brunch">Brunch</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Food Description</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="editFoodDescription" required>
                                <button class="btn btn-outline-success" type="button" id="editFetchNutritionBtn">
                                    <i class="fas fa-sync-alt"></i> Re‑fetch
                                </button>
                            </div>
                            <!-- Optional details area for edit modal (we'll reuse the same logic but hide it) -->
                            <div id="editNutritionDetails" class="mt-2 small"></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Calories</label>
                                <input type="number" class="form-control" id="editCalories" placeholder="kcal">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fat (g)</label>
                                <input type="number" step="0.1" class="form-control" id="editFat" placeholder="g">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Protein (g)</label>
                                <input type="number" step="0.1" class="form-control" id="editProtein" placeholder="g">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Carbs (g)</label>
                                <input type="number" step="0.1" class="form-control" id="editCarbs" placeholder="g">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveEditBtn">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global data
        let mealsData = {};
        let profileData = {};

        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date').value = today;
            loadProfile();
            loadMeals();

            document.getElementById('fetchCaloriesBtn').addEventListener('click', () => {
                fetchNutritionAndDisplay('foodDescription', 'nutritionDetails', 'calorieResult', 
                                        'caloriesField', 'fatField', 'proteinField', 'carbsField');
            });
            document.getElementById('mealForm').addEventListener('submit', addMeal);
            document.getElementById('editFetchNutritionBtn').addEventListener('click', () => {
                // For edit modal, we just update the fields, but we can also show a simple message
                fetchNutritionAndDisplay('editFoodDescription', 'editNutritionDetails', null,
                                        'editCalories', 'editFat', 'editProtein', 'editCarbs', true);
            });
            document.getElementById('saveEditBtn').addEventListener('click', updateMeal);
            document.getElementById('saveProfileBtn').addEventListener('click', saveProfile);
        });

        // Enhanced fetch function: displays each food item and updates totals
        function fetchNutritionAndDisplay(descField, detailsDivId, resultDivId, calField, fatField, protField, carbField, isEdit = false) {
            const food = document.getElementById(descField).value.trim();
            if (!food) {
                alert('Please enter a food description.');
                return;
            }

            const detailsDiv = document.getElementById(detailsDivId);
            if (detailsDiv) detailsDiv.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Looking up...';
            if (resultDivId) document.getElementById(resultDivId).innerHTML = '';

            fetch('?action=get_calories&food=' + encodeURIComponent(food))
                .then(response => response.json())
                .then(data => {
                    if (data.items && data.items.length > 0) {
                        // Build list of items
                        let itemsHtml = '<div class="food-item-list"><strong>Items found:</strong>';
                        let totalCal = 0, totalFat = 0, totalProt = 0, totalCarb = 0;

                        data.items.forEach(item => {
                            const name = item.name || 'Food';
                            const cal = item.calories || 0;
                            const fat = item.fat_total_g || 0;
                            const prot = item.protein_g || 0;
                            const carb = item.carbohydrates_total_g || 0;

                            totalCal += cal;
                            totalFat += fat;
                            totalProt += prot;
                            totalCarb += carb;

                            itemsHtml += `
                                <div class="food-item-row">
                                    <span>${name}</span>
                                    <span>
                                        <span class="badge bg-secondary">${cal} kcal</span>
                                        <span class="badge-macro ms-1">F:${fat.toFixed(1)}g</span>
                                        <span class="badge-macro ms-1">P:${prot.toFixed(1)}g</span>
                                        <span class="badge-macro ms-1">C:${carb.toFixed(1)}g</span>
                                    </span>
                                </div>`;
                        });

                        itemsHtml += `<div class="mt-2 fw-bold">Total: ${totalCal.toFixed(0)} kcal | F:${totalFat.toFixed(1)}g P:${totalProt.toFixed(1)}g C:${totalCarb.toFixed(1)}g</div>`;
                        itemsHtml += '</div>';

                        if (detailsDiv) detailsDiv.innerHTML = itemsHtml;

                        // Update hidden fields
                        document.getElementById(calField).value = Math.round(totalCal);
                        document.getElementById(fatField).value = totalFat.toFixed(1);
                        document.getElementById(protField).value = totalProt.toFixed(1);
                        document.getElementById(carbField).value = totalCarb.toFixed(1);

                        if (resultDivId) {
                            document.getElementById(resultDivId).innerHTML = `✅ Ready to add – total ${Math.round(totalCal)} kcal.`;
                        }
                    } else if (data.error) {
                        if (detailsDiv) detailsDiv.innerHTML = `<span class="text-danger">⚠️ ${data.error}</span>`;
                        if (resultDivId) document.getElementById(resultDivId).innerHTML = '';
                        // Clear fields
                        document.getElementById(calField).value = '';
                        document.getElementById(fatField).value = '';
                        document.getElementById(protField).value = '';
                        document.getElementById(carbField).value = '';
                    } else {
                        if (detailsDiv) detailsDiv.innerHTML = '<span class="text-warning">⚠️ No nutrition data found.</span>';
                        if (resultDivId) document.getElementById(resultDivId).innerHTML = '';
                        document.getElementById(calField).value = '';
                        document.getElementById(fatField).value = '';
                        document.getElementById(protField).value = '';
                        document.getElementById(carbField).value = '';
                    }
                })
                .catch(error => {
                    console.error(error);
                    if (detailsDiv) detailsDiv.innerHTML = '<span class="text-danger">❌ Error connecting to service.</span>';
                    if (resultDivId) document.getElementById(resultDivId).innerHTML = '';
                });
        }

        // Add meal (unchanged, uses hidden fields)
        function addMeal(e) {
            e.preventDefault();
            const meal = {
                date: document.getElementById('date').value,
                meal_type: document.getElementById('mealType').value,
                food_description: document.getElementById('foodDescription').value.trim(),
                calories: document.getElementById('caloriesField').value || null,
                fat: document.getElementById('fatField').value || null,
                protein: document.getElementById('proteinField').value || null,
                carbs: document.getElementById('carbsField').value || null
            };
            fetch('?action=add_meal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(meal)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('mealForm').reset();
                    document.getElementById('date').value = new Date().toISOString().split('T')[0];
                    document.getElementById('caloriesField').value = '';
                    document.getElementById('fatField').value = '';
                    document.getElementById('proteinField').value = '';
                    document.getElementById('carbsField').value = '';
                    document.getElementById('nutritionDetails').innerHTML = '';
                    document.getElementById('calorieResult').innerHTML = '';
                    loadMeals();
                } else {
                    alert('Error: ' + data.error);
                }
            });
        }

        // Profile functions (unchanged)
        function loadProfile() {
            fetch('?action=get_profile')
                .then(r => r.json())
                .then(data => {
                    profileData = data;
                    updateProfileDisplay();
                    document.getElementById('profileName').value = data.name || '';
                    document.getElementById('profileWeight').value = data.weight_kg || '';
                    document.getElementById('profileHeight').value = data.height_cm || '';
                    document.getElementById('profileAge').value = data.age || '';
                    document.getElementById('profileGender').value = data.gender || '';
                });
        }

        function updateProfileDisplay() {
            const p = profileData;
            let html = '';
            if (p.name) html += `<strong>Name:</strong> ${p.name}<br>`;
            if (p.weight_kg) html += `<strong>Weight:</strong> ${p.weight_kg} kg<br>`;
            if (p.height_cm) html += `<strong>Height:</strong> ${p.height_cm} cm<br>`;
            if (p.age) html += `<strong>Age:</strong> ${p.age}<br>`;
            if (p.bmi) {
                html += `<strong>BMI:</strong> ${p.bmi} (${p.bmi_category})<br>`;
            } else {
                html += `<span class="text-muted">Enter weight & height to see BMI</span>`;
            }
            document.getElementById('profileDetails').innerHTML = html || 'No profile data yet. Click Edit to add.';
        }

        function saveProfile() {
            const profile = {
                name: document.getElementById('profileName').value.trim() || null,
                weight_kg: parseFloat(document.getElementById('profileWeight').value) || null,
                height_cm: parseFloat(document.getElementById('profileHeight').value) || null,
                age: parseInt(document.getElementById('profileAge').value) || null,
                gender: document.getElementById('profileGender').value || null
            };
            fetch('?action=update_profile', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(profile)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('profileModal')).hide();
                    loadProfile();
                } else {
                    alert('Error: ' + data.error);
                }
            });
        }

        // Load meals and render (unchanged)
        function loadMeals() {
            fetch('?action=get_meals')
                .then(r => r.json())
                .then(data => {
                    mealsData = data;
                    renderMeals();
                });
        }

        function renderMeals() {
            const container = document.getElementById('mealsContainer');
            container.innerHTML = '';
            if (Object.keys(mealsData).length === 0) {
                container.innerHTML = '<p class="text-center text-muted">No meals recorded yet.</p>';
                return;
            }

            const weight = profileData.weight_kg;
            const maintenance = weight ? weight * 24 : null;

            for (const [date, meals] of Object.entries(mealsData)) {
                let dailyCal = 0, dailyFat = 0, dailyProtein = 0, dailyCarbs = 0;
                meals.forEach(m => {
                    if (m.calories) dailyCal += parseInt(m.calories);
                    if (m.fat_g) dailyFat += parseFloat(m.fat_g);
                    if (m.protein_g) dailyProtein += parseFloat(m.protein_g);
                    if (m.carbs_g) dailyCarbs += parseFloat(m.carbs_g);
                });

                const formattedDate = new Date(date).toLocaleDateString(undefined, { weekday:'short', year:'numeric', month:'short', day:'numeric' });

                const card = document.createElement('div');
                card.className = 'profile-card meal-card p-0 mb-4';
                card.innerHTML = `
                    <div class="card-header bg-white py-3 px-4 d-flex flex-wrap align-items-center justify-content-between">
                        <h5 class="mb-0 fw-semibold">${formattedDate}</h5>
                        <div class="d-flex align-items-center gap-3">
                            <span class="total-badge">
                                <i class="fas fa-fire me-1 text-warning"></i> ${dailyCal} kcal
                            </span>
                            ${maintenance ? `
                                <span class="badge ${dailyCal > maintenance ? 'bg-danger' : (dailyCal < maintenance ? 'bg-warning text-dark' : 'bg-success')} rounded-pill px-3 py-2">
                                    ${dailyCal > maintenance ? '⬆️ Above' : (dailyCal < maintenance ? '⬇️ Below' : '✅ Matches')} maintenance
                                </span>
                            ` : ''}
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush" id="list-${date.replace(/-/g,'')}"></ul>
                    </div>
                `;
                container.appendChild(card);
                const list = card.querySelector(`#list-${date.replace(/-/g,'')}`);

                meals.forEach(meal => {
                    const item = document.createElement('li');
                    item.className = 'list-group-item d-flex justify-content-between align-items-center px-4 py-3';
                    item.innerHTML = `
                        <div>
                            <span class="badge bg-secondary me-2">${meal.meal_type}</span>
                            <span>${meal.food_description}</span>
                            ${meal.calories ? `<span class="badge bg-success ms-2">${meal.calories} kcal</span>` : ''}
                            <div class="mt-1">
                                ${meal.fat_g ? `<span class="badge-macro me-1"><i class="fas fa-droplet me-1" style="color:#e67e22;"></i>F ${meal.fat_g}g</span>` : ''}
                                ${meal.protein_g ? `<span class="badge-macro me-1"><i class="fas fa-bolt me-1" style="color:#27ae60;"></i>P ${meal.protein_g}g</span>` : ''}
                                ${meal.carbs_g ? `<span class="badge-macro me-1"><i class="fas fa-apple-alt me-1" style="color:#2980b9;"></i>C ${meal.carbs_g}g</span>` : ''}
                            </div>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary me-1 rounded-circle" onclick="editMeal(${meal.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger rounded-circle" onclick="deleteMeal(${meal.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;
                    list.appendChild(item);
                });
            }
        }

        // Edit meal: populate modal (unchanged)
        window.editMeal = function(id) {
            fetch('?action=get_meals')
                .then(r => r.json())
                .then(mealsByDate => {
                    let meal = null;
                    for (const d in mealsByDate) {
                        const found = mealsByDate[d].find(m => m.id == id);
                        if (found) { meal = found; break; }
                    }
                    if (meal) {
                        document.getElementById('editId').value = meal.id;
                        document.getElementById('editDate').value = meal.date;
                        document.getElementById('editMealType').value = meal.meal_type;
                        document.getElementById('editFoodDescription').value = meal.food_description;
                        document.getElementById('editCalories').value = meal.calories || '';
                        document.getElementById('editFat').value = meal.fat_g || '';
                        document.getElementById('editProtein').value = meal.protein_g || '';
                        document.getElementById('editCarbs').value = meal.carbs_g || '';
                        document.getElementById('editNutritionDetails').innerHTML = ''; // clear previous
                        new bootstrap.Modal(document.getElementById('editModal')).show();
                    }
                });
        };

        function updateMeal() {
            const meal = {
                id: document.getElementById('editId').value,
                date: document.getElementById('editDate').value,
                meal_type: document.getElementById('editMealType').value,
                food_description: document.getElementById('editFoodDescription').value.trim(),
                calories: document.getElementById('editCalories').value || null,
                fat: document.getElementById('editFat').value || null,
                protein: document.getElementById('editProtein').value || null,
                carbs: document.getElementById('editCarbs').value || null
            };
            fetch('?action=update_meal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(meal)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                    loadMeals();
                } else {
                    alert('Error: ' + data.error);
                }
            });
        }

        window.deleteMeal = function(id) {
            if (confirm('Delete this meal?')) {
                fetch('?action=delete_meal', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) loadMeals();
                    else alert('Error: ' + data.error);
                });
            }
        };
    </script>
</body>
</html>