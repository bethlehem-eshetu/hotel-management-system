<?php
// src/router.php
require_once __DIR__ . '/bootstrap.php';

function route($method, $uri, $routes) {
    // ========== CRITICAL FIX: Check if it's a static file FIRST ==========
    // Since we're on InfinityFree, everything is in htdocs (no public subdirectory)
    $requestedFile = __DIR__ . '/../' . $uri;
    
    // If it's a static file that exists, let the web server handle it
    if (file_exists($requestedFile) && !is_dir($requestedFile)) {
        $fileExtension = pathinfo($requestedFile, PATHINFO_EXTENSION);
        $staticFileExtensions = ['html', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
        
        if (in_array(strtolower($fileExtension), $staticFileExtensions)) {
            error_log("Static file requested: $uri - letting web server handle it");
            return false; // Exit router, let web server serve the file
        }
    }
    
    // ========== Continue with API routing ==========
    header('Content-Type: application/json');
    
    error_log("=== ROUTER START ===");
    error_log("Method: $method");
    error_log("URI: $uri");
    
    // Check if bootstrap loaded properly
    if (!function_exists('db')) {
        error_log("ERROR: bootstrap.php not loaded properly!");
        http_response_code(500);
        echo json_encode(['error' => 'Server configuration error']);
        return;
    }
    
    // Test database connection
    try {
        $testDb = db();
        if (!$testDb) {
            error_log("ERROR: db() returned null!");
            throw new Exception("Database connection failed");
        }
        error_log("Database connection OK");
    } catch (Exception $e) {
        error_log("Database test failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }
    
    if (!isset($routes[$method])) {
        error_log("Method $method not allowed");
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    // Normalize URI
    $uri = '/' . trim($uri, '/');
    error_log("Normalized URI: $uri");
    
    /* ---------- Exact match ---------- */
    if (isset($routes[$method][$uri])) {
        [$controller, $action] = explode('@', $routes[$method][$uri]);
        error_log("Exact match: $controller@$action");
        
        $controllerFile = __DIR__ . "/controllers/$controller.php";
        if (!file_exists($controllerFile)) {
            error_log("Controller file not found: $controllerFile");
            http_response_code(500);
            echo json_encode(['error' => "Controller $controller not found"]);
            return;
        }
        
        require_once $controllerFile;
        
        if (!class_exists($controller)) {
            error_log("Controller class not found: $controller");
            http_response_code(500);
            echo json_encode(['error' => "Controller class $controller not found"]);
            return;
        }
        
        try {
            $ctrl = new $controller();
            error_log("Controller instantiated: " . get_class($ctrl));
        } catch (Exception $e) {
            error_log("Failed to instantiate controller: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create controller: ' . $e->getMessage()]);
            return;
        }
        
        if (!method_exists($ctrl, $action)) {
            error_log("Method not found: $action in $controller");
            http_response_code(500);
            echo json_encode(['error' => "Method $action not found in $controller"]);
            return;
        }
        
        // Only POST receives body
        if ($method === 'POST') {
            $rawInput = file_get_contents("php://input");
            $data = json_decode($rawInput, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON']);
                return;
            }
            echo $ctrl->$action($data);
        } else {
            echo $ctrl->$action();
        }
        error_log("=== ROUTER END ===");
        return;
    }
    
    /* ---------- Placeholder match ---------- */
    foreach ($routes[$method] as $pattern => $handler) {
        // Skip if it's an exact match pattern (no placeholders)
        if (!str_contains($pattern, '{')) continue;
        
        // Skip patterns that could conflict with exact matches we already checked
        // e.g., skip /api/services/{service}/options if we're looking for /api/services/taxis
        if (strpos($pattern, '/api/services/') === 0 && strpos($uri, '/api/services/') === 0) {
            // Check if this is a parameterized route that shouldn't match exact service names
            $serviceNames = ['taxis', 'restaurants', 'tours', 'status', 'discover'];
            $uriParts = explode('/', $uri);
            $lastPart = end($uriParts);
            
            if (in_array($lastPart, $serviceNames)) {
                error_log("Skipping placeholder route $pattern for exact service $lastPart");
                continue;
            }
        }
        
        // Convert /api/hotels/{id} → regex
        $regex = preg_replace('/\{[^\/]+\}/', '([^\/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        
        if (preg_match($regex, $uri, $matches)) {
            array_shift($matches); // remove full match
            
            error_log("Pattern match: $pattern");
            error_log("Matches: " . print_r($matches, true));
            
            [$controller, $action] = explode('@', $handler);
            error_log("Handler: $controller@$action");
            
            $controllerFile = __DIR__ . "/controllers/$controller.php";
            if (!file_exists($controllerFile)) {
                error_log("Controller file not found: $controllerFile");
                http_response_code(500);
                echo json_encode(['error' => "Controller $controller not found"]);
                return;
            }
            
            require_once $controllerFile;
            
            if (!class_exists($controller)) {
                error_log("Controller class not found: $controller");
                http_response_code(500);
                echo json_encode(['error' => "Controller class $controller not found"]);
                return;
            }
            
            try {
                $ctrl = new $controller();
                error_log("Controller instantiated: " . get_class($ctrl));
            } catch (Exception $e) {
                error_log("Failed to instantiate controller: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create controller: ' . $e->getMessage()]);
                return;
            }
            
            if (!method_exists($ctrl, $action)) {
                error_log("Method not found: $action in $controller");
                http_response_code(500);
                echo json_encode(['error' => "Method $action not found in $controller"]);
                return;
            }
            
            // Extract parameter names from pattern
            preg_match_all('/\{([^}]+)\}/', $pattern, $paramNames);
            $paramNames = $paramNames[1];
            
            // Create associative array of parameters
            $params = [];
            foreach ($paramNames as $index => $name) {
                if (isset($matches[$index])) {
                    $params[$name] = $matches[$index];
                }
            }
            
            error_log("Extracted params: " . print_r($params, true));
            
            // Handle different HTTP methods
            switch ($method) {
                case 'GET':
                case 'DELETE':
                    // For GET/DELETE with single ID parameter, pass it directly
                    if (count($params) === 1 && isset($params['id'])) {
                        error_log("Passing single ID parameter: " . $params['id']);
                        echo $ctrl->$action($params['id']);
                    } else {
                        error_log("Passing params array");
                        echo $ctrl->$action($params);
                    }
                    break;
                    
                case 'POST':
                    $rawInput = file_get_contents("php://input");
                    $data = json_decode($rawInput, true);
                    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid JSON']);
                        return;
                    }
    
                    // Special handling for routes like /api/hotels/{id}/rooms
                    if (!empty($params)) {
                        if (count($params) === 1) {
                            $paramValue = reset($params);
                            $paramKey = key($params);
            
                            if ($paramKey === 'id' || $paramKey === 'hotelId') {
                                error_log("POST with single ID: $paramKey = $paramValue");
                                echo $ctrl->$action($paramValue, $data);
                            } else {
                                error_log("POST with single param (not ID): $paramKey = $paramValue");
                                echo $ctrl->$action($data, $params);
                            }
                        } else {
                            error_log("POST with multiple params");
                            echo $ctrl->$action($data, $params);
                        }
                    } else {
                        error_log("POST without params");
                        echo $ctrl->$action($data);
                    }
                    break;
                   
                case 'PATCH':
                case 'PUT':
                    $rawInput = file_get_contents("php://input");
                    $data = json_decode($rawInput, true);
                    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid JSON']);
                        return;
                    }
                    // For PATCH/PUT with single ID parameter, pass ID first then data
                    if (count($params) === 1 && isset($params['id'])) {
                        error_log("PATCH with single ID: " . $params['id'] . " + data");
                        echo $ctrl->$action($params['id'], $data);
                    } else {
                        error_log("PATCH with params array: params + data");
                        echo $ctrl->$action($params, $data);
                    }
                    break;
                    
                default:
                    http_response_code(405);
                    echo json_encode(['error' => 'Method not allowed']);
                    return;
            }
            error_log("=== ROUTER END ===");
            return;
        }
    }
    
    error_log("No route matched");
    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'uri' => $uri]);
    error_log("=== ROUTER END ===");
}