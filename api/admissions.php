<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Only POST requests are allowed.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['message' => 'Invalid form data.']);
    exit;
}

$required = [
    'academic_year',
    'class_applied',
    'student_name',
    'date_of_birth',
    'gender',
    'father_name',
    'mother_name',
    'parent_mobile',
    'address',
    'transport_required',
];

foreach ($required as $field) {
    if (empty(trim((string) ($payload[$field] ?? '')))) {
        http_response_code(422);
        echo json_encode(['message' => 'Please complete all required fields.']);
        exit;
    }
}

if (empty($payload['declaration'])) {
    http_response_code(422);
    echo json_encode(['message' => 'Please accept the admission declaration.']);
    exit;
}

$mobile = preg_replace('/\D+/', '', (string) $payload['parent_mobile']);
if (strlen($mobile) < 10 || strlen($mobile) > 12) {
    http_response_code(422);
    echo json_encode(['message' => 'Please enter a valid parent mobile number.']);
    exit;
}

$email = trim((string) ($payload['parent_email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['message' => 'Please enter a valid parent email address.']);
    exit;
}

$aadhaar = preg_replace('/\D+/', '', (string) ($payload['aadhaar_number'] ?? ''));
if ($aadhaar !== '' && strlen($aadhaar) !== 12) {
    http_response_code(422);
    echo json_encode(['message' => 'Aadhaar number must be 12 digits.']);
    exit;
}

try {
    $statement = db()->prepare(
        'INSERT INTO admissions (
            academic_year,
            class_applied,
            student_name,
            date_of_birth,
            gender,
            blood_group,
            aadhaar_number,
            previous_school,
            father_name,
            mother_name,
            parent_mobile,
            parent_email,
            address,
            transport_required,
            last_class_passed
        ) VALUES (
            :academic_year,
            :class_applied,
            :student_name,
            :date_of_birth,
            :gender,
            :blood_group,
            :aadhaar_number,
            :previous_school,
            :father_name,
            :mother_name,
            :parent_mobile,
            :parent_email,
            :address,
            :transport_required,
            :last_class_passed
        )'
    );

    $statement->execute([
        'academic_year' => trim((string) $payload['academic_year']),
        'class_applied' => trim((string) $payload['class_applied']),
        'student_name' => trim((string) $payload['student_name']),
        'date_of_birth' => trim((string) $payload['date_of_birth']),
        'gender' => trim((string) $payload['gender']),
        'blood_group' => trim((string) ($payload['blood_group'] ?? '')),
        'aadhaar_number' => $aadhaar,
        'previous_school' => trim((string) ($payload['previous_school'] ?? '')),
        'father_name' => trim((string) $payload['father_name']),
        'mother_name' => trim((string) $payload['mother_name']),
        'parent_mobile' => $mobile,
        'parent_email' => $email,
        'address' => trim((string) $payload['address']),
        'transport_required' => trim((string) $payload['transport_required']),
        'last_class_passed' => trim((string) ($payload['last_class_passed'] ?? '')),
    ]);

    echo json_encode([
        'message' => 'Thank you. ROSELAND SCHOOL has received your admission enquiry.',
        'admission_id' => db()->lastInsertId(),
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['message' => 'Database error. Please check the school database setup.']);
}
