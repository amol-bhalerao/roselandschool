<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ErpController
{
    private const STAGES = ['enquiry', 'application', 'screening', 'offer', 'fee_paid', 'enrolled'];

    public function __construct(private Database $db)
    {
    }

    public function summary(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $data = [
            'admission_pipeline' => $this->countTable($pdo, 'erp_admission_applications'),
            'active_students' => $this->scalarInt($pdo, "SELECT COUNT(*) FROM erp_students WHERE status = 'active'"),
            'staff' => $this->countTable($pdo, 'erp_staff'),
            'fee_due' => $this->scalarFloat($pdo, "SELECT COALESCE(SUM(amount - discount_amount - paid_amount), 0) FROM erp_fee_invoices WHERE status IN ('due','partial','overdue')"),
            'documents_pending' => $this->scalarInt($pdo, "SELECT COUNT(*) FROM erp_admission_applications WHERE stage IN ('application','screening','offer')"),
            'portal_users' => $this->scalarInt($pdo, 'SELECT COUNT(*) FROM users'),
        ];
        $data['conversion_rate'] = $this->conversionRate($pdo);
        return $this->json($response, $data);
    }

    public function admissions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $st = $pdo->query(
            "SELECT a.id, a.applicant_name, a.guardian_name, a.phone, a.email, a.stage, a.score, a.source,
                    a.aadhar_no, a.follow_up_at, a.follow_up_note, a.details_json,
                    c.name AS class_name, a.created_at
             FROM erp_admission_applications a
             JOIN erp_classes c ON c.id = a.target_class_id
             ORDER BY a.created_at DESC, a.id DESC"
        );
        $rows = array_map(fn (array $row): array => $this->admissionRow($row), $st->fetchAll());
        return $this->json($response, [
            'data' => $rows,
            'meta' => [
                'stage_counts' => $this->stageCounts($pdo),
                'conversion_rate' => $this->conversionRate($pdo),
            ],
        ]);
    }

    public function createAdmission(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $guardian = trim((string) ($body['guardian'] ?? '')) ?: 'Not captured at enquiry';
        $phone = trim((string) ($body['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            return $this->json($response, ['error' => 'Applicant name and phone are required'], 422);
        }

        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $yearId = $this->activeAcademicYearId($pdo, $institutionId);
        $className = $this->normalizeClassName((string) ($body['className'] ?? 'Grade 10'));
        $classId = $this->classIdByName($pdo, $institutionId, $className);
        $source = trim((string) ($body['source'] ?? 'Website')) ?: 'Website';
        $email = trim((string) ($body['email'] ?? '')) ?: null;
        $followUpAt = trim((string) ($body['nextFollowUp'] ?? '')) ?: null;
        $followUpNote = trim((string) ($body['remarks'] ?? '')) ?: null;

        $details = is_array($body['details'] ?? null) ? $body['details'] : [];
        $details['Admission Class'] = $className;
        $sectionId = $this->defaultSectionIdForClass($pdo, $classId);
        if ($sectionId > 0 && empty($details['Class Section Id'])) {
            $details['Class Section Id'] = (string) $sectionId;
        }
        $aadhar = trim((string) ($body['aadharNo'] ?? ($details['aadhar_no'] ?? ''))) ?: null;
        $st = $pdo->prepare(
            'INSERT INTO erp_admission_applications
             (institution_id, academic_year_id, target_class_id, applicant_name, guardian_name, phone, email, aadhar_no, follow_up_at, follow_up_note, details_json, stage, score, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stage = filter_var($body['directAdmission'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'application' : 'enquiry';
        $details['admission_status'] = $stage === 'application' ? 'Pending document verification' : ($details['admission_status'] ?? 'Enquiry');
        $st->execute([$institutionId, $yearId, $classId, $name, $guardian, $phone, $email, $aadhar, $followUpAt, $followUpNote, json_encode($details), $stage, 0, $source]);
        $id = (int) $pdo->lastInsertId();
        $this->audit($pdo, $request, 'admissions', 'created', 'erp_admission_applications', (string) $id, ['applicant' => $name]);
        return $this->json($response, ['data' => $this->findAdmission($pdo, $id)], 201);
    }

    public function createPublicAdmissionApplication(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $details = is_array($body['details'] ?? null) ? $body['details'] : $body;
        $first = trim((string) ($details['First Name'] ?? $body['firstName'] ?? ''));
        $middle = trim((string) ($details['Middle / Father Name'] ?? $body['middleName'] ?? ''));
        $last = trim((string) ($details['Last Name / Surname'] ?? $body['lastName'] ?? ''));
        $name = trim(implode(' ', array_filter([$first, $middle, $last]))) ?: trim((string) ($body['name'] ?? ''));
        $phone = trim((string) ($details['Mobile No'] ?? $body['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            return $this->json($response, ['error' => 'Student name and mobile number are required'], 422);
        }
        if (!preg_match('/^[0-9+()\\-\\s]{7,20}$/', $phone)) {
            return $this->json($response, ['error' => 'Enter a valid mobile number'], 422);
        }
        $digitsOnly = fn (string $value): string => preg_replace('/\D+/', '', $value) ?? '';
        $aadharRaw = trim((string) ($details['Aadhar No'] ?? $body['aadharNo'] ?? ''));
        if ($aadharRaw !== '' && strlen($digitsOnly($aadharRaw)) !== 12) {
            return $this->json($response, ['error' => 'Aadhaar number must be 12 digits'], 422);
        }
        $pinRaw = trim((string) ($details['Pin Code'] ?? ''));
        if ($pinRaw !== '' && strlen($digitsOnly($pinRaw)) !== 6) {
            return $this->json($response, ['error' => 'Pincode must be 6 digits'], 422);
        }
        foreach (['SSC Year', 'HSC / XIth Year'] as $yearField) {
            $year = trim((string) ($details[$yearField] ?? ''));
            if ($year !== '' && !preg_match('/^(19|20)\d{2}$/', $year)) {
                return $this->json($response, ['error' => $yearField . ' must be a valid 4 digit year'], 422);
            }
        }
        $ifscRaw = strtoupper(trim((string) ($details['IFSC Code'] ?? '')));
        if ($ifscRaw !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifscRaw)) {
            return $this->json($response, ['error' => 'Enter a valid IFSC code'], 422);
        }
        $outOfMarks = (float) ($details['Out of Marks'] ?? 0);
        $obtainedMarks = (float) ($details['Obtained Marks'] ?? 0);
        if ($outOfMarks > 0 && $obtainedMarks > $outOfMarks) {
            return $this->json($response, ['error' => 'Obtained marks cannot be greater than total marks'], 422);
        }

        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $yearId = $this->activeAcademicYearId($pdo, $institutionId);
        $className = $this->normalizeClassName((string) ($details['Admission Class'] ?? $body['className'] ?? 'BA Year 1'));
        $classId = $this->classIdByName($pdo, $institutionId, $className);
        $sectionId = $this->defaultSectionIdForClass($pdo, $classId);
        $aadhar = $aadharRaw !== '' ? $digitsOnly($aadharRaw) : null;
        $email = trim((string) ($details['Email Id'] ?? $details['Email'] ?? $body['email'] ?? '')) ?: null;
        $details['Admission Class'] = $className;
        if ($sectionId > 0 && empty($details['Class Section Id'])) {
            $details['Class Section Id'] = (string) $sectionId;
        }
        $details['admission_status'] = 'Submitted from website - pending document verification';
        $details['public_website_admission_form'] = true;
        $guardian = $middle !== '' ? $middle : trim((string) ($body['guardian'] ?? 'Website admission form'));

        $st = $pdo->prepare(
            'INSERT INTO erp_admission_applications
             (institution_id, academic_year_id, target_class_id, applicant_name, guardian_name, phone, email, aadhar_no, details_json, stage, score, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$institutionId, $yearId, $classId, $name, $guardian, $phone, $email, $aadhar, json_encode($details), 'application', 0, 'Website admission form']);
        $id = (int) $pdo->lastInsertId();
        $this->audit($pdo, $request, 'admissions', 'public_admission_form_submitted', 'erp_admission_applications', (string) $id, ['applicant' => $name]);
        return $this->json($response, ['data' => $this->findAdmission($pdo, $id)], 201);
    }

    public function createPublicAdmissionApplicationLite(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $details = is_array($body['details'] ?? null) ? $body['details'] : $body;
        $selectLikeFields = [
            'Admission To', 'Faculty', 'Admission Class', 'Gender', 'Blood Group', 'Marital Status',
            'Mother Tongue', 'Religion', 'Country', 'State', 'District', 'Taluka',
            'Permanent Address Same as Correspondence Address', 'Nationality', 'Country of Citizenship',
            'Domicile Of State', 'Residential proof', 'Category', 'Economically Weaker Section (EWS)',
            'Permanent benchmark disabilities', 'Divyang', 'Claim reservation benefits', 'SSC Month',
            'HSC / XIth Month', 'Qualification Type', 'Name of Qualification', 'Qualification Status',
            'Board/University', 'Education from Foreign Board', 'Result Type',
            'Academic Gap', 'Dual Degree Interested',
            'Are you Employed or Self-Employed', 'Occupation of Guardian', 'Guardian from EBC',
            'Account Holder',
        ];
        $selectLikeLookup = array_flip($selectLikeFields);
        foreach ($details as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $clean = trim(preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');
            if (str_starts_with((string) $key, 'Document:')) {
                $details[$key] = match (strtolower($clean)) {
                    'submitted', 'with me', 'with me now' => 'With me now',
                    'pending', 'required', 'will submit later' => 'Will submit later',
                    'not applicable' => 'Not applicable',
                    default => $clean ?: 'Will submit later',
                };
                continue;
            }
            if (str_contains(strtolower((string) $key), 'email')) {
                $details[$key] = strtolower($clean);
                continue;
            }
            $details[$key] = isset($selectLikeLookup[(string) $key]) ? $clean : strtoupper($clean);
        }
        $details['Alternate Email Id'] = '';
        $details["Parent's/Guardian's Email ID"] = '';
        $details['Whatsapp Number'] = (string) ($details['Mobile No'] ?? '');
        $details["Parent's/Guardian's Whatsapp Number"] = (string) ($details["Parent's/Guardian's Mobile Number"] ?? '');
        $first = trim((string) ($details['First Name'] ?? ''));
        $middle = trim((string) ($details['Middle / Father Name'] ?? ''));
        $last = trim((string) ($details['Last Name / Surname'] ?? ''));
        $name = trim(implode(' ', array_filter([$first, $middle, $last])));
        $phone = trim((string) ($details['Mobile No'] ?? ''));
        if ($name === '' || $phone === '') {
            return $this->json($response, ['error' => 'Student name and mobile number are required'], 422);
        }
        if (!preg_match('/^[0-9+()\\-\\s]{10,20}$/', $phone)) {
            return $this->json($response, ['error' => 'Enter a valid mobile number'], 422);
        }
        $aadharRaw = trim((string) ($details['Aadhar No'] ?? ''));
        $aadharDigits = preg_replace('/\D+/', '', $aadharRaw) ?: '';
        if ($aadharRaw !== '' && strlen($aadharDigits) !== 12) {
            return $this->json($response, ['error' => 'Aadhaar number must be 12 digits'], 422);
        }
        $pinRaw = trim((string) ($details['Pin Code'] ?? ''));
        $pinDigits = preg_replace('/\D+/', '', $pinRaw) ?: '';
        if ($pinRaw !== '' && strlen($pinDigits) !== 6) {
            return $this->json($response, ['error' => 'Pincode must be 6 digits'], 422);
        }
        foreach (['SSC Year', 'HSC / XIth Year'] as $yearField) {
            $year = trim((string) ($details[$yearField] ?? ''));
            if ($year !== '' && !preg_match('/^(19|20)\d{2}$/', $year)) {
                return $this->json($response, ['error' => $yearField . ' must be a valid 4 digit year'], 422);
            }
        }
        $ifsc = strtoupper(trim((string) ($details['IFSC Code'] ?? '')));
        if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            return $this->json($response, ['error' => 'Enter a valid IFSC code'], 422);
        }
        $outOfMarks = (float) ($details['Out of Marks'] ?? 0);
        $obtainedMarks = (float) ($details['Obtained Marks'] ?? 0);
        if ($outOfMarks > 0 && $obtainedMarks > $outOfMarks) {
            return $this->json($response, ['error' => 'Obtained marks cannot be greater than total marks'], 422);
        }

        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $yearId = $this->activeAcademicYearId($pdo, $institutionId);
        $className = $this->normalizeClassName((string) ($details['Admission Class'] ?? 'BA Year 1'));
        $classId = $this->classIdByName($pdo, $institutionId, $className);
        $sectionId = $this->defaultSectionIdForClass($pdo, $classId);
        $details['Admission Class'] = $className;
        if ($sectionId > 0 && empty($details['Class Section Id'])) {
            $details['Class Section Id'] = (string) $sectionId;
        }
        $details['admission_status'] = 'Submitted from website - pending document verification';
        $details['public_website_admission_form'] = true;

        $st = $pdo->prepare(
            'INSERT INTO erp_admission_applications
             (institution_id, academic_year_id, target_class_id, applicant_name, guardian_name, phone, email, aadhar_no, details_json, stage, score, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $institutionId,
            $yearId,
            $classId,
            $name,
            $middle !== '' ? $middle : 'Website admission form',
            $phone,
            trim((string) ($details['Email Id'] ?? '')) ?: null,
            $aadharDigits !== '' ? $aadharDigits : null,
            json_encode($details),
            'application',
            0,
            'Website admission form',
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->audit($pdo, $request, 'admissions', 'public_admission_form_submitted', 'erp_admission_applications', (string) $id, ['applicant' => $name]);
        return $this->json($response, [
            'data' => [
                'numericId' => $id,
                'id' => 'APP-' . date('Y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'name' => $name,
                'className' => $className,
                'status' => 'Pending document verification',
            ],
        ], 201);
    }

    public function publicAdmissionMasters(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $classes = $pdo->query('SELECT id, name, level_order FROM erp_classes ORDER BY level_order, id')->fetchAll();
        $mappingRows = $pdo->query("SELECT payload_json FROM erp_saved_records WHERE module = 'Class course mapping' ORDER BY created_at DESC")->fetchAll();
        $mappings = [];
        foreach ($mappingRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            $classId = (int) ($payload['classId'] ?? 0);
            if ($classId > 0 && !isset($mappings[$classId])) {
                $mappings[$classId] = ['course' => (string) ($payload['course'] ?? ''), 'faculty' => (string) ($payload['faculty'] ?? '')];
            }
        }
        $classNamesById = [];
        $publicClasses = [];
        foreach ($classes as $class) {
            $classId = (int) $class['id'];
            $classNamesById[$classId] = (string) $class['name'];
            $mapping = $mappings[$classId] ?? null;
            if (!$mapping || trim($mapping['course']) === '' || trim($mapping['faculty']) === '') {
                continue;
            }
            $publicClasses[] = ['id' => $classId, 'name' => (string) $class['name'], 'course' => $mapping['course'], 'faculty' => $mapping['faculty'], 'level_order' => (int) $class['level_order']];
        }
        $courseMap = [];
        $facultyMap = [];
        foreach ($publicClasses as $class) {
            $courseMap[$class['course']] = ['course' => $class['course']];
            $facultyMap[$class['course'] . '|' . $class['faculty']] = ['course' => $class['course'], 'faculty' => $class['faculty']];
        }
        $subjectRows = $pdo->query('SELECT sec.class_id, sub.code, sub.name, sub.subject_type, ss.semester_no, ss.is_mandatory FROM erp_section_subjects ss JOIN erp_sections sec ON sec.id = ss.section_id JOIN erp_subjects sub ON sub.id = ss.subject_id ORDER BY sec.class_id, ss.semester_no, sub.name')->fetchAll();
        $subjects = [];
        foreach ($subjectRows as $row) {
            $classId = (int) $row['class_id'];
            $mapping = $mappings[$classId] ?? null;
            if (!$mapping || empty($classNamesById[$classId])) {
                continue;
            }
            $subjects[] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'category' => (string) $row['subject_type'],
                'semester' => (int) ($row['semester_no'] ?? 0),
                'compulsory' => (int) $row['is_mandatory'] === 1,
                'className' => $classNamesById[$classId],
                'course' => $mapping['course'],
                'faculty' => $mapping['faculty'],
            ];
        }
        return $this->json($response, ['data' => ['courses' => array_values($courseMap), 'faculties' => array_values($facultyMap), 'classes' => $publicClasses, 'subjects' => $subjects]]);
    }

    public function lookupPincode(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $pin = (string) ($args['pin'] ?? '');
        if (!preg_match('/^\d{6}$/', $pin)) {
            return $this->json($response, ['error' => 'Pincode must be 6 digits'], 422);
        }

        $url = 'https://api.postalpincode.in/pincode/' . rawurlencode($pin);
        $raw = null;
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'CampusSuite/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $raw = curl_exec($curl);
            curl_close($curl);
        }
        if (!is_string($raw) || $raw === '') {
            $raw = @file_get_contents($url, false, stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
                'http' => [
                    'timeout' => 8,
                    'header' => "User-Agent: CampusSuite/1.0\r\n",
                ],
            ]));
        }

        $payload = is_string($raw) ? json_decode($raw, true) : null;
        $postOffices = is_array($payload[0]['PostOffice'] ?? null) ? $payload[0]['PostOffice'] : [];
        $places = [];
        foreach ($postOffices as $office) {
            if (!is_array($office)) {
                continue;
            }
            $name = trim((string) ($office['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $places[] = [
                'name' => $name,
                'district' => trim((string) ($office['District'] ?? '')),
                'state' => trim((string) ($office['State'] ?? '')),
                'taluka' => trim((string) ($office['Block'] ?? $office['Taluk'] ?? $office['Division'] ?? '')),
            ];
        }

        if ($places === []) {
            return $this->json($response, ['data' => [], 'message' => 'No address details found for this pincode']);
        }

        return $this->json($response, ['data' => $places]);
    }

    public function createPublicEnquiry(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $phone = trim((string) ($body['phone'] ?? ''));
        $className = trim((string) ($body['className'] ?? 'BA Year 1')) ?: 'BA Year 1';
        $source = trim((string) ($body['source'] ?? 'Website')) ?: 'Website';
        $followUpAt = trim((string) ($body['nextFollowUp'] ?? '')) ?: null;
        $remarks = trim((string) ($body['remarks'] ?? ''));
        if ($name === '' || $phone === '') {
            return $this->json($response, ['error' => 'Student name and mobile number are required'], 422);
        }
        if (!preg_match('/^[0-9+()\\-\\s]{7,20}$/', $phone)) {
            return $this->json($response, ['error' => 'Enter a valid mobile number'], 422);
        }

        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $yearId = $this->activeAcademicYearId($pdo, $institutionId);
        $classId = $this->classIdByName($pdo, $institutionId, $className);
        $details = [
            'public_website_enquiry' => true,
            'current_followup_status' => $remarks,
            'preferred_class' => $className,
        ];
        $st = $pdo->prepare(
            'INSERT INTO erp_admission_applications
             (institution_id, academic_year_id, target_class_id, applicant_name, guardian_name, phone, email, aadhar_no, follow_up_at, follow_up_note, details_json, stage, score, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$institutionId, $yearId, $classId, $name, 'Website enquiry', $phone, null, null, $followUpAt, $remarks ?: null, json_encode($details), 'enquiry', 0, $source]);
        $id = (int) $pdo->lastInsertId();
        $this->audit($pdo, $request, 'admissions', 'public_enquiry_created', 'erp_admission_applications', (string) $id, ['applicant' => $name, 'source' => $source]);
        return $this->json($response, ['data' => $this->findAdmission($pdo, $id)], 201);
    }

    public function advanceAdmission(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT stage, details_json FROM erp_admission_applications WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $stage = (string) ($row['stage'] ?? '');
        if ($stage === '') {
            return $this->json($response, ['error' => 'Admission application not found'], 404);
        }
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        if (is_array($body['documents'] ?? null)) {
            $details['document_verification'] = [
                'documents' => $body['documents'],
                'result' => (string) ($body['result'] ?? 'Some documents pending'),
                'note' => (string) ($body['note'] ?? ''),
                'verified_at' => date('Y-m-d H:i:s'),
            ];
        }
        $idx = array_search($stage, self::STAGES, true);
        $next = self::STAGES[min(($idx === false ? 0 : $idx) + 1, count(self::STAGES) - 1)];
        $pdo->prepare('UPDATE erp_admission_applications SET stage = ?, details_json = ? WHERE id = ?')->execute([$next, json_encode($details), $id]);
        $this->audit($pdo, $request, 'admissions', 'advanced', 'erp_admission_applications', (string) $id, ['from' => $stage, 'to' => $next, 'documents' => $body['documents'] ?? null]);
        return $this->json($response, ['data' => $this->findAdmission($pdo, $id)]);
    }

    public function saveAdmissionDocuments(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'clerk'], true)) {
            return $this->json($response, ['error' => 'Only admin, principal or clerk can verify and collect admission documents'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT stage, details_json FROM erp_admission_applications WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return $this->json($response, ['error' => 'Admission application not found'], 404);
        }
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        $verified = is_array($body['verifiedDocuments'] ?? null) ? $body['verifiedDocuments'] : [];
        $collected = is_array($body['collectedDocuments'] ?? null) ? $body['collectedDocuments'] : [];
        $pending = is_array($body['pendingDocuments'] ?? null) ? $body['pendingDocuments'] : [];
        $existingCollected = is_array($details['original_documents'] ?? null) ? $details['original_documents'] : [];
        foreach ($collected as $doc) {
            if (!is_array($doc) || trim((string) ($doc['name'] ?? '')) === '') {
                continue;
            }
            $existingCollected[] = [
                'name' => trim((string) $doc['name']),
                'collected_at' => trim((string) ($doc['collectedAt'] ?? '')) ?: date('Y-m-d H:i:s'),
                'returned_at' => trim((string) ($doc['returnedAt'] ?? '')),
                'storage_note' => trim((string) ($doc['storageNote'] ?? '')),
                'status' => trim((string) ($doc['returnedAt'] ?? '')) !== '' ? 'Returned' : 'With institute',
            ];
        }
        $details['document_verification'] = [
            'verified_documents' => $verified,
            'pending_documents' => $pending,
            'result' => (string) ($body['result'] ?? 'Some documents pending'),
            'note' => (string) ($body['note'] ?? ''),
            'verified_by_role' => $role,
            'verified_at' => date('Y-m-d H:i:s'),
        ];
        $details['original_documents'] = $existingCollected;
        $details['pending_documents'] = $pending;

        $nextStage = (string) $row['stage'];
        if ($nextStage === 'enquiry') {
            $nextStage = 'application';
        }
        if (empty($pending) && in_array($nextStage, ['application', 'screening'], true)) {
            $nextStage = 'offer';
        } elseif (!empty($pending) && $nextStage === 'application') {
            $nextStage = 'screening';
        }

        $pdo->prepare('UPDATE erp_admission_applications SET stage = ?, details_json = ? WHERE id = ?')->execute([$nextStage, json_encode($details), $id]);
        $this->audit($pdo, $request, 'admissions', 'documents_saved', 'erp_admission_applications', (string) $id, ['pending' => $pending, 'collected' => $collected]);
        return $this->json($response, ['data' => $this->findAdmission($pdo, $id)]);
    }

    public function updateAdmission(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $role = (string) $request->getAttribute('user_role');
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $incomingDetails = is_array($body['details'] ?? null) ? $body['details'] : [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT a.*, c.name AS class_name
             FROM erp_admission_applications a
             JOIN erp_classes c ON c.id = a.target_class_id
             WHERE a.id = ?'
        );
        $st->execute([$id]);
        $app = $st->fetch();
        if (!$app) {
            return $this->json($response, ['error' => 'Admission application not found'], 404);
        }

        $details = array_merge(json_decode((string) ($app['details_json'] ?? '{}'), true) ?: [], $incomingDetails);
        $subjects = [];
        foreach ($details as $key => $value) {
            if (str_starts_with((string) $key, 'Subject:') && $value === 'selected') {
                $code = substr((string) $key, 8);
                if ($code !== '__add_subject__') {
                    $subjects[] = ['code' => $code, 'status' => 'selected'];
                }
                unset($details[$key]);
            }
        }
        if ($subjects) {
            $details['subjects'] = $subjects;
        }
        $className = trim((string) ($incomingDetails['Admission Class'] ?? $body['className'] ?? $app['class_name']));
        $classId = (int) $app['target_class_id'];
        if (in_array($role, ['admin', 'accountant'], true) || !in_array((string) $app['stage'], ['fee_paid', 'enrolled'], true)) {
            $classId = $this->classIdByName($pdo, (int) $app['institution_id'], $className);
        }
        $sectionId = (int) ($incomingDetails['Class Section Id'] ?? $body['sectionId'] ?? $app['target_section_id'] ?? 0);
        if ($sectionId <= 0) {
            $sec = $pdo->prepare('SELECT id FROM erp_sections WHERE class_id = ? ORDER BY name LIMIT 1');
            $sec->execute([$classId]);
            $sectionId = (int) $sec->fetchColumn();
        }

        $first = trim((string) ($incomingDetails['First Name'] ?? ''));
        $middle = trim((string) ($incomingDetails['Middle / Father Name'] ?? ''));
        $last = trim((string) ($incomingDetails['Last Name / Surname'] ?? ''));
        $applicantName = trim(implode(' ', array_filter([$first, $middle, $last]))) ?: (string) $app['applicant_name'];
        $guardian = $middle !== '' ? $middle : (string) $app['guardian_name'];
        $phone = trim((string) ($incomingDetails['Mobile No'] ?? $app['phone']));
        $aadhar = trim((string) ($incomingDetails['Aadhar No'] ?? $app['aadhar_no'] ?? '')) ?: null;
        $nextStage = (string) $app['stage'] === 'enquiry' ? 'application' : (string) $app['stage'];
        $details['admission_status'] = in_array($role, ['admin', 'accountant'], true) ? 'Ready for fee confirmation' : 'Pending accountant confirmation';

        $update = $pdo->prepare(
            'UPDATE erp_admission_applications
             SET target_class_id = ?, target_section_id = ?, applicant_name = ?, guardian_name = ?, phone = ?, aadhar_no = ?, details_json = ?, stage = ?
             WHERE id = ?'
        );
        $update->execute([$classId, $sectionId, $applicantName, $guardian, $phone, $aadhar, json_encode($details), $nextStage, $id]);
        $this->audit($pdo, $request, 'admissions', 'details_saved', 'erp_admission_applications', (string) $id, ['status' => $details['admission_status'], 'section_id' => $sectionId]);

        return $this->json($response, ['data' => $this->findAdmission($pdo, $id)]);
    }

    public function convertAdmission(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can confirm paid admission conversion'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $sectionId = (int) ($body['sectionId'] ?? 0);
        $paymentAmount = (float) ($body['paymentAmount'] ?? 0);
        $paymentMethod = strtolower(str_replace(' ', '_', trim((string) ($body['paymentMethod'] ?? 'cash')) ?: 'cash'));
        $paymentReference = trim((string) ($body['paymentReference'] ?? '')) ?: null;
        if ($paymentAmount <= 0) {
            return $this->json($response, ['error' => 'Admission payment is required before confirming admission'], 422);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT a.*, c.name AS class_name
             FROM erp_admission_applications a
             JOIN erp_classes c ON c.id = a.target_class_id
             WHERE a.id = ?'
        );
        $st->execute([$id]);
        $app = $st->fetch();
        if (!$app) {
            return $this->json($response, ['error' => 'Admission application not found'], 404);
        }
        if ($sectionId <= 0) {
            $sec = $pdo->prepare('SELECT id FROM erp_sections WHERE class_id = ? ORDER BY name LIMIT 1');
            $sec->execute([(int) $app['target_class_id']]);
            $sectionId = (int) $sec->fetchColumn();
        }

        $details = json_decode((string) ($app['details_json'] ?? '{}'), true) ?: [];
        $parts = preg_split('/\s+/', trim((string) $app['applicant_name']));
        $firstName = (string) ($parts[0] ?? $app['applicant_name']);
        $lastName = count($parts) > 1 ? (string) end($parts) : '';
        $admissionNo = 'ADM-' . date('Y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);

        $student = $pdo->prepare(
            'INSERT INTO erp_students
             (institution_id, academic_year_id, class_id, section_id, admission_no, roll_no, first_name, last_name, gender, date_of_birth, email, phone, status, admitted_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
        );
        $student->execute([
            (int) $app['institution_id'],
            (int) $app['academic_year_id'],
            (int) $app['target_class_id'],
            $sectionId,
            $admissionNo,
            null,
            $firstName,
            $lastName,
            (string) ($details['gender'] ?? 'other'),
            (string) ($details['date_of_birth'] ?? '2008-01-01'),
            $app['email'],
            $app['phone'],
            'active',
        ]);
        $studentId = (int) $pdo->lastInsertId();

        $guardian = $pdo->prepare('INSERT INTO erp_guardians (institution_id, name, relation, email, phone, address) VALUES (?, ?, ?, ?, ?, ?)');
        $guardian->execute([(int) $app['institution_id'], $app['guardian_name'], 'Guardian', $app['email'], $app['phone'], (string) ($details['residential_address'] ?? '')]);
        $guardianId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO erp_student_guardians (student_id, guardian_id, is_primary) VALUES (?, ?, 1)')->execute([$studentId, $guardianId]);

        $plans = $pdo->prepare('SELECT id, amount, due_on FROM erp_fee_plans WHERE class_id = ? ORDER BY due_on, id');
        $plans->execute([(int) $app['target_class_id']]);
        $fees = $plans->fetchAll();
        if ($fees) {
            $firstInvoiceId = null;
            $firstAmount = 0.0;
            foreach ($fees as $fee) {
                $invoiceId = $this->createInvoiceForFeePlan($pdo, $studentId, (int) $fee['id'], (float) $fee['amount'], (string) $fee['due_on']);
                if ($firstInvoiceId === null) {
                    $firstInvoiceId = $invoiceId;
                    $firstAmount = (float) $fee['amount'];
                }
            }
            $posted = min($paymentAmount, $firstAmount > 0 ? $firstAmount : $paymentAmount);
            $receiptNo = 'RCT-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_payments') + 1), 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO erp_fee_payments (invoice_id, receipt_no, amount, method, paid_at, reference_no) VALUES (?, ?, ?, ?, NOW(), ?)')
                ->execute([$firstInvoiceId, $receiptNo, $posted, $paymentMethod, $paymentReference]);
            $invoiceStatus = $posted >= $firstAmount ? 'paid' : 'partial';
            $pdo->prepare('UPDATE erp_fee_invoices SET paid_amount = ?, status = ? WHERE id = ?')->execute([$posted, $invoiceStatus, $firstInvoiceId]);
        } else {
            $invoice = $this->createFeeInvoiceForStudent($pdo, $studentId, 'Admission fee', $paymentAmount);
            $receiptNo = 'RCT-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_payments') + 1), 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO erp_fee_payments (invoice_id, receipt_no, amount, method, paid_at, reference_no) VALUES (?, ?, ?, ?, NOW(), ?)')
                ->execute([(int) $invoice['id'], $receiptNo, $paymentAmount, $paymentMethod, $paymentReference]);
            $pdo->prepare('UPDATE erp_fee_invoices SET paid_amount = ?, status = ? WHERE id = ?')->execute([$paymentAmount, 'paid', (int) $invoice['id']]);
        }

        $pdo->prepare('UPDATE erp_admission_applications SET stage = ?, target_section_id = ? WHERE id = ?')->execute(['enrolled', $sectionId, $id]);
        $this->audit($pdo, $request, 'admissions', 'converted', 'erp_admission_applications', (string) $id, ['student_id' => $studentId, 'section_id' => $sectionId, 'payment_amount' => $paymentAmount]);

        return $this->json($response, ['data' => $this->findAdmission($pdo, $id), 'studentId' => $studentId, 'receiptNo' => $receiptNo ?? null], 201);
    }

    public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = [];

        $adminRows = $pdo->query('SELECT id, email, display_name, role, created_at FROM users ORDER BY id')->fetchAll();
        foreach ($adminRows as $row) {
            $rows[] = [
                'id' => 'USR-' . (int) $row['id'],
                'name' => $row['display_name'] ?: $row['email'],
                'email' => $row['email'],
                'role' => $row['role'] === 'admin' ? 'Super Admin' : ucfirst((string) $row['role']),
                'persona' => $row['role'] === 'admin' ? 'Admin' : ucfirst((string) $row['role']),
                'status' => $row['role'] === 'invite_pending' ? 'Invite pending' : 'Active',
                'lastLogin' => 'Tracked after audit login events',
                'mfa' => $row['role'] === 'admin',
            ];
        }

        foreach ($pdo->query("SELECT id, first_name, last_name, email, role FROM erp_staff WHERE status = 'active' ORDER BY id LIMIT 25")->fetchAll() as $row) {
            $role = (string) $row['role'];
            $rows[] = [
                'id' => 'STF-' . (int) $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'email' => $row['email'] ?: 'not-set@staff.local',
                'role' => $role,
                'persona' => $role,
                'status' => 'Active',
                'lastLogin' => 'Not connected',
                'mfa' => false,
            ];
        }

        foreach ($pdo->query('SELECT id, name, email FROM erp_guardians ORDER BY id LIMIT 25')->fetchAll() as $row) {
            $rows[] = [
                'id' => 'PAR-' . (int) $row['id'],
                'name' => $row['name'],
                'email' => $row['email'] ?: 'not-set@parent.local',
                'role' => 'Parent Portal',
                'persona' => 'Parent',
                'status' => 'Invite pending',
                'lastLogin' => 'Never',
                'mfa' => false,
            ];
        }

        foreach ($pdo->query('SELECT id, first_name, last_name, email FROM erp_students ORDER BY id LIMIT 25')->fetchAll() as $row) {
            $rows[] = [
                'id' => 'STD-' . (int) $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'email' => $row['email'] ?: 'not-set@student.local',
                'role' => 'Student Portal',
                'persona' => 'Student',
                'status' => 'Active',
                'lastLogin' => 'Not connected',
                'mfa' => false,
            ];
        }

        return $this->json($response, ['data' => $rows]);
    }

    public function inviteUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $persona = trim((string) ($body['persona'] ?? 'Teacher')) ?: 'Teacher';
        $role = trim((string) ($body['role'] ?? 'Teacher')) ?: 'Teacher';
        $password = (string) ($body['password'] ?? '');
        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['error' => 'Valid name and email are required'], 422);
        }

        $pdo = $this->db->pdo();
        $passwordHash = password_hash($password !== '' ? $password : bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
        $inviteToken = bin2hex(random_bytes(24));
        $inviteHash = hash('sha256', $inviteToken);
        $storedRole = $this->roleSlug($role);
        $activateNow = strlen($password) >= 8;
        $st = $pdo->prepare(
            'INSERT INTO users (email, display_name, password_hash, role, invite_token_hash, invite_expires_at, invite_accepted_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), ?)'
        );
        try {
            $st->execute([$email, $name, $passwordHash, $storedRole, $inviteHash, $activateNow ? date('Y-m-d H:i:s') : null]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->json($response, ['error' => 'A user with this email already exists'], 409);
            }
            throw $e;
        }
        $id = (int) $pdo->lastInsertId();
        $this->audit($pdo, $request, 'users', 'invited', 'users', (string) $id, ['persona' => $persona, 'role' => $role]);
        $inviteUrl = '/accept-invite?token=' . $inviteToken;

        return $this->json($response, [
            'data' => [
                'id' => 'USR-' . $id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'persona' => $persona,
                'status' => $activateNow ? 'Active' : 'Invite pending',
                'lastLogin' => $activateNow ? 'Activated by admin' : 'Never',
                'mfa' => false,
                'inviteUrl' => $inviteUrl,
            ],
        ], 201);
    }

    public function reports(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $reports = [
            [
                'name' => 'Admission conversion funnel',
                'owner' => 'Admissions',
                'cadence' => 'Daily',
                'access' => 'Principal, Admin, Counsellor',
                'metric' => $this->conversionRate($pdo) . '% enquiry-to-offer',
            ],
            [
                'name' => 'Lead source ROI',
                'owner' => 'Admissions',
                'cadence' => 'Weekly',
                'access' => 'Management, Admin',
                'metric' => $this->topSourceMetric($pdo),
            ],
            [
                'name' => 'Missing admission documents',
                'owner' => 'Registrar',
                'cadence' => 'Daily',
                'access' => 'Registrar, Admin',
                'metric' => $this->scalarInt($pdo, "SELECT COUNT(*) FROM erp_admission_applications WHERE stage IN ('application','screening','offer')") . ' pending files',
            ],
            [
                'name' => 'Fee collection ageing',
                'owner' => 'Finance',
                'cadence' => 'Daily',
                'access' => 'Accountant, Principal',
                'metric' => 'INR ' . number_format($this->scalarFloat($pdo, "SELECT COALESCE(SUM(amount - discount_amount - paid_amount), 0) FROM erp_fee_invoices WHERE status IN ('due','partial','overdue')"), 2) . ' due',
            ],
            [
                'name' => 'Attendance risk register',
                'owner' => 'Academics',
                'cadence' => 'Daily',
                'access' => 'Principal, Teacher',
                'metric' => $this->scalarInt($pdo, "SELECT COUNT(*) FROM erp_attendance_records WHERE status IN ('absent','late')") . ' risk signals today',
            ],
            [
                'name' => 'User access audit',
                'owner' => 'IT Admin',
                'cadence' => 'Weekly',
                'access' => 'Super Admin',
                'metric' => $this->countTable($pdo, 'erp_audit_logs') . ' audit events',
            ],
        ];

        return $this->json($response, [
            'data' => $reports,
            'summary' => [
                'saved_reports' => count($reports),
                'scheduled_exports' => 14,
                'restricted_reports' => 5,
                'audit_events' => $this->countTable($pdo, 'erp_audit_logs'),
            ],
        ]);
    }

    public function generateReport(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $module = trim((string) ($body['module'] ?? 'Admissions')) ?: 'Admissions';
        $range = trim((string) ($body['range'] ?? 'This academic year')) ?: 'This academic year';
        $groupBy = trim((string) ($body['groupBy'] ?? 'Class')) ?: 'Class';
        $pdo = $this->db->pdo();

        $rows = match (strtolower($module)) {
            'finance' => $this->financeReportRows($pdo),
            'attendance' => $this->attendanceReportRows($pdo),
            'users' => $this->userReportRows($pdo),
            default => $this->admissionReportRows($pdo),
        };

        $reportId = 'RPT-' . date('Ymd-His');
        $this->audit($pdo, $request, 'reports', 'generated', 'erp_report', $reportId, [
            'module' => $module,
            'range' => $range,
            'groupBy' => $groupBy,
        ]);

        return $this->json($response, [
            'data' => [
                'id' => $reportId,
                'title' => $module . ' report',
                'module' => $module,
                'range' => $range,
                'groupBy' => $groupBy,
                'generatedAt' => date(DATE_ATOM),
                'rows' => $rows,
            ],
        ], 201);
    }

    public function masters(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);

        $this->ensureSavedRecordsSchema($pdo);
        $classes = $pdo->query('SELECT id, name, level_order FROM erp_classes ORDER BY level_order, id')->fetchAll();
        $courseRows = $pdo->query("SELECT id, name, payload_json FROM erp_saved_records WHERE module IN ('Course master', 'Course faculty master') ORDER BY created_at DESC")->fetchAll();
        $courseMap = [];
        foreach ($courseRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            $course = trim((string) ($payload['course'] ?? $row['name'] ?? ''));
            if ($course === '' || isset($courseMap[$course])) {
                continue;
            }
            $courseMap[$course] = [
                'id' => (string) $row['id'],
                'course' => $course,
                'notes' => (string) ($payload['notes'] ?? ''),
            ];
        }
        $courses = array_values($courseMap);
        $facultyRows = $pdo->query("SELECT id, name, payload_json FROM erp_saved_records WHERE module = 'Course faculty master' ORDER BY created_at DESC")->fetchAll();
        $facultyMap = [];
        foreach ($facultyRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            $course = trim((string) ($payload['course'] ?? ''));
            $faculty = trim((string) ($payload['faculty'] ?? ''));
            if ($course === '' || $faculty === '' || isset($facultyMap[$course . '|' . $faculty])) {
                continue;
            }
            $facultyMap[$course . '|' . $faculty] = [
                'id' => (string) $row['id'],
                'course' => $course,
                'faculty' => $faculty,
                'notes' => (string) ($payload['notes'] ?? ''),
            ];
        }
        $faculties = array_values($facultyMap);
        $classMappingRows = $pdo->query("SELECT payload_json FROM erp_saved_records WHERE module = 'Class course mapping' ORDER BY created_at DESC")->fetchAll();
        $classMappings = [];
        foreach ($classMappingRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            $classId = (int) ($payload['classId'] ?? 0);
            if ($classId > 0 && !isset($classMappings[$classId])) {
                $classMappings[$classId] = [
                    'course' => (string) ($payload['course'] ?? ''),
                    'faculty' => (string) ($payload['faculty'] ?? ''),
                ];
            }
        }
        foreach ($classes as &$class) {
            $mapping = $classMappings[(int) $class['id']] ?? [];
            $class['course'] = (string) ($mapping['course'] ?? '');
            $class['faculty'] = (string) ($mapping['faculty'] ?? '');
        }
        unset($class);
        $sections = $pdo->query('SELECT s.id, s.class_id, c.name AS class_name, s.name, s.capacity FROM erp_sections s JOIN erp_classes c ON c.id = s.class_id ORDER BY c.level_order, s.name')->fetchAll();
        $subjects = $pdo->query('SELECT id, code, name, subject_type FROM erp_subjects ORDER BY name')->fetchAll();
        $sectionSubjects = $pdo->query('SELECT ss.section_id, ss.subject_id, ss.semester_no, ss.is_mandatory, sub.code, sub.name FROM erp_section_subjects ss JOIN erp_subjects sub ON sub.id = ss.subject_id ORDER BY ss.section_id, ss.semester_no, sub.name')->fetchAll();
        $subjectGroups = $pdo->query('SELECT g.id, g.course_name, g.group_name, g.description, GROUP_CONCAT(sub.name ORDER BY sub.name SEPARATOR ", ") AS subjects FROM erp_subject_groups g LEFT JOIN erp_subject_group_subjects gs ON gs.group_id = g.id LEFT JOIN erp_subjects sub ON sub.id = gs.subject_id GROUP BY g.id, g.course_name, g.group_name, g.description ORDER BY g.course_name, g.group_name')->fetchAll();
        $staff = $pdo->query("SELECT id, employee_no, CONCAT(first_name, ' ', last_name) AS name, role FROM erp_staff WHERE status = 'active' ORDER BY first_name")->fetchAll();
        $feePlans = $pdo->query('SELECT f.id, f.name, f.amount, f.due_on, c.name AS class_name FROM erp_fee_plans f LEFT JOIN erp_classes c ON c.id = f.class_id ORDER BY f.due_on, f.id')->fetchAll();
        $routes = $pdo->query('SELECT id, name, route_code, monthly_fee FROM erp_transport_routes ORDER BY name')->fetchAll();
        $hostels = $pdo->query("SELECT h.id, h.name, h.hostel_type, CONCAT(st.first_name, ' ', st.last_name) AS warden_name FROM erp_hostels h LEFT JOIN erp_staff st ON st.id = h.warden_staff_id ORDER BY h.name")->fetchAll();
        $documentRows = $pdo->query("SELECT id, name, payload_json FROM erp_saved_records WHERE module = 'Document master' ORDER BY created_at DESC")->fetchAll();
        $documents = array_map(static function (array $row): array {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            return [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'purpose' => (string) ($payload['purpose'] ?? 'Admission'),
                'requirement' => (string) ($payload['requirement'] ?? 'Required'),
                'appliesTo' => (string) ($payload['appliesTo'] ?? 'All students'),
                'condition' => (string) ($payload['condition'] ?? ''),
                'notes' => (string) ($payload['notes'] ?? ''),
            ];
        }, $documentRows);
        $students = $pdo->query("SELECT s.id, CONCAT(s.first_name, ' ', s.last_name) AS name, s.first_name, s.last_name, s.admission_no, s.date_of_birth, s.phone, s.email, c.name AS class_name, sec.name AS section_name, sec.id AS section_id FROM erp_students s JOIN erp_classes c ON c.id = s.class_id JOIN erp_sections sec ON sec.id = s.section_id ORDER BY s.first_name")->fetchAll();

        return $this->json($response, [
            'data' => [
                'institution' => [
                    'id' => $institutionId,
                    'name' => (string) $pdo->query('SELECT name FROM erp_institutions ORDER BY id LIMIT 1')->fetchColumn(),
                ],
                'courses' => $courses,
                'faculties' => $faculties,
                'classes' => $classes,
                'sections' => $sections,
                'subjects' => $subjects,
                'sectionSubjects' => $sectionSubjects,
                'subjectGroups' => $subjectGroups,
                'staff' => $staff,
                'feePlans' => $feePlans,
                'routes' => $routes,
                'hostels' => $hostels,
                'documents' => $documents,
                'students' => $students,
            ],
        ]);
    }

    public function saveMasterClass(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can update classes directly'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $levelOrder = (int) ($body['levelOrder'] ?? 0);
        $course = trim((string) ($body['course'] ?? ''));
        $faculty = trim((string) ($body['faculty'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'Class name is required'], 422);
        }
        if ($course === '' || $faculty === '') {
            return $this->json($response, ['error' => 'Course and faculty are required for class registration'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        $st = $pdo->prepare(
            'INSERT INTO erp_classes (institution_id, name, level_order)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE level_order = VALUES(level_order)'
        );
        $st->execute([$institutionId, $name, $levelOrder]);
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $lookup = $pdo->prepare('SELECT id FROM erp_classes WHERE institution_id = ? AND name = ?');
            $lookup->execute([$institutionId, $name]);
            $id = (int) $lookup->fetchColumn();
        }
        $payload = ['classId' => $id, 'name' => $name, 'levelOrder' => $levelOrder, 'course' => $course, 'faculty' => $faculty] + $this->activeAcademicYearMeta($pdo, $institutionId);
        $mappingId = 'CLASSMAP-' . $id . '-' . date('Ymd-His');
        $map = $pdo->prepare(
            'INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $userId = $request->getAttribute('user_id');
        $map->execute([
            $mappingId,
            $userId !== null ? (int) $userId : null,
            'Class course mapping',
            $name,
            strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '-', $name), 0, 40)),
            'Active',
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $this->audit($pdo, $request, 'masters', 'class_saved', 'erp_class', (string) $id, $payload);
        return $this->json($response, ['data' => ['id' => $id, 'name' => $name, 'level_order' => $levelOrder, 'course' => $course, 'faculty' => $faculty]], 201);
    }

    public function saveMasterCourse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can manage courses and faculties'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $course = trim((string) ($body['course'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        if ($course === '') {
            return $this->json($response, ['error' => 'Course is required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        $yearMeta = $this->activeAcademicYearMeta($pdo, $institutionId);
        $existingRows = $pdo->query("SELECT id, payload_json FROM erp_saved_records WHERE module = 'Course master' ORDER BY created_at DESC")->fetchAll();
        foreach ($existingRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            if (strcasecmp(trim((string) ($payload['course'] ?? '')), $course) === 0) {
                $payload['notes'] = $notes !== '' ? $notes : (string) ($payload['notes'] ?? '');
                $payload += $yearMeta;
                $update = $pdo->prepare('UPDATE erp_saved_records SET payload_json = ?, name = ?, status = ? WHERE id = ?');
                $update->execute([json_encode($payload, JSON_THROW_ON_ERROR), $course, 'Active', (string) $row['id']]);
                return $this->json($response, ['data' => ['id' => (string) $row['id'], 'course' => $course, 'notes' => (string) ($payload['notes'] ?? '')]], 200);
            }
        }
        $id = 'COURSE-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $payload = ['course' => $course, 'notes' => $notes] + $yearMeta;
        $st = $pdo->prepare(
            'INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $userId = $request->getAttribute('user_id');
        $st->execute([
            $id,
            $userId !== null ? (int) $userId : null,
            'Course master',
            $course,
            strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '-', $course), 0, 40)),
            'Active',
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $this->audit($pdo, $request, 'masters', 'course_saved', 'erp_saved_records', $id, $payload);
        return $this->json($response, ['data' => ['id' => $id, 'course' => $course, 'notes' => $notes]], 201);
    }

    public function saveMasterFaculty(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can manage faculties'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $course = trim((string) ($body['course'] ?? ''));
        $faculty = trim((string) ($body['faculty'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        if ($course === '' || $faculty === '') {
            return $this->json($response, ['error' => 'Course and faculty are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        $yearMeta = $this->activeAcademicYearMeta($pdo, $institutionId);
        $existingRows = $pdo->query("SELECT id, payload_json FROM erp_saved_records WHERE module = 'Course faculty master' ORDER BY created_at DESC")->fetchAll();
        foreach ($existingRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            if (strcasecmp(trim((string) ($payload['course'] ?? '')), $course) === 0 && strcasecmp(trim((string) ($payload['faculty'] ?? '')), $faculty) === 0) {
                $payload['notes'] = $notes !== '' ? $notes : (string) ($payload['notes'] ?? '');
                $payload += $yearMeta;
                $update = $pdo->prepare('UPDATE erp_saved_records SET payload_json = ?, name = ?, status = ? WHERE id = ?');
                $update->execute([json_encode($payload, JSON_THROW_ON_ERROR), $course . ' - ' . $faculty, 'Active', (string) $row['id']]);
                return $this->json($response, ['data' => ['id' => (string) $row['id'], 'course' => $course, 'faculty' => $faculty, 'notes' => (string) ($payload['notes'] ?? '')]], 200);
            }
        }
        $id = 'FAC-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $payload = ['course' => $course, 'faculty' => $faculty, 'notes' => $notes] + $yearMeta;
        $st = $pdo->prepare(
            'INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $userId = $request->getAttribute('user_id');
        $st->execute([
            $id,
            $userId !== null ? (int) $userId : null,
            'Course faculty master',
            $course . ' - ' . $faculty,
            strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '-', $course . '-' . $faculty), 0, 40)),
            'Active',
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $this->audit($pdo, $request, 'masters', 'faculty_saved', 'erp_saved_records', $id, $payload);
        return $this->json($response, ['data' => ['id' => $id, 'course' => $course, 'faculty' => $faculty, 'notes' => $notes]], 201);
    }

    public function saveMasterSection(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can update class sections directly'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $classId = (int) ($body['classId'] ?? 0);
        $name = trim((string) ($body['section'] ?? $body['name'] ?? ''));
        $capacity = max(1, (int) ($body['capacity'] ?? 40));
        if ($classId <= 0 || $name === '') {
            return $this->json($response, ['error' => 'Class and section are required'], 422);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'INSERT INTO erp_sections (class_id, name, capacity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)'
        );
        $st->execute([$classId, $name, $capacity]);
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $lookup = $pdo->prepare('SELECT id FROM erp_sections WHERE class_id = ? AND name = ?');
            $lookup->execute([$classId, $name]);
            $id = (int) $lookup->fetchColumn();
        }
        $this->audit($pdo, $request, 'masters', 'section_saved', 'erp_section', (string) $id, ['classId' => $classId, 'name' => $name, 'capacity' => $capacity]);
        return $this->json($response, ['data' => ['id' => $id, 'class_id' => $classId, 'name' => $name, 'capacity' => $capacity]], 201);
    }

    public function saveMasterSubject(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can update subjects directly'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        $name = trim((string) ($body['name'] ?? ''));
        $category = strtolower(trim((string) ($body['category'] ?? 'core')));
        $type = in_array($category, ['optional', 'elective', 'bifocal', 'second language'], true) ? 'elective' : 'core';
        $sectionId = (int) ($body['sectionId'] ?? 0);
        $semester = max(1, min(2, (int) ($body['semester'] ?? 1)));
        $mandatory = ((string) ($body['mandatory'] ?? 'Yes')) === 'Yes' ? 1 : 0;
        if ($code === '' || $name === '') {
            return $this->json($response, ['error' => 'Subject code and subject name are required'], 422);
        }
        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $st = $pdo->prepare(
            'INSERT INTO erp_subjects (institution_id, code, name, subject_type)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), subject_type = VALUES(subject_type)'
        );
        $st->execute([$institutionId, $code, $name, $type]);
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $lookup = $pdo->prepare('SELECT id FROM erp_subjects WHERE institution_id = ? AND code = ?');
            $lookup->execute([$institutionId, $code]);
            $id = (int) $lookup->fetchColumn();
        }
        $this->audit($pdo, $request, 'masters', 'subject_saved', 'erp_subject', (string) $id, ['code' => $code, 'name' => $name, 'type' => $type]);
        $mapping = null;
        if ($sectionId > 0) {
            $map = $pdo->prepare(
                'INSERT INTO erp_section_subjects (section_id, subject_id, semester_no, is_mandatory)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE semester_no = VALUES(semester_no), is_mandatory = VALUES(is_mandatory)'
            );
            $map->execute([$sectionId, $id, $semester, $mandatory]);
            $mapping = ['section_id' => $sectionId, 'subject_id' => $id, 'semester_no' => $semester, 'is_mandatory' => $mandatory];
            $this->audit($pdo, $request, 'masters', 'section_subject_saved', 'erp_section_subject', $sectionId . ':' . $id, [
                'sectionId' => $sectionId,
                'subjectCode' => $code,
                'semester' => $semester,
                'mandatory' => $mandatory,
            ]);
        }
        return $this->json($response, ['data' => ['id' => $id, 'code' => $code, 'name' => $name, 'subject_type' => $type, 'mapping' => $mapping]], 201);
    }

    public function saveMasterSectionSubject(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can map subjects directly'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $sectionId = (int) ($body['sectionId'] ?? 0);
        $subjectCode = strtoupper(trim((string) ($body['subjectCode'] ?? '')));
        $semester = max(1, min(2, (int) ($body['semester'] ?? 1)));
        $mandatory = ((string) ($body['mandatory'] ?? 'Yes')) === 'Yes' ? 1 : 0;
        if ($sectionId <= 0 || $subjectCode === '') {
            return $this->json($response, ['error' => 'Section and subject are required'], 422);
        }
        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $subject = $pdo->prepare('SELECT id, name FROM erp_subjects WHERE institution_id = ? AND code = ?');
        $subject->execute([$institutionId, $subjectCode]);
        $subjectRow = $subject->fetch();
        if (!$subjectRow) {
            return $this->json($response, ['error' => 'Subject not found in master list'], 422);
        }
        $st = $pdo->prepare(
            'INSERT INTO erp_section_subjects (section_id, subject_id, semester_no, is_mandatory)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE semester_no = VALUES(semester_no), is_mandatory = VALUES(is_mandatory)'
        );
        $st->execute([$sectionId, (int) $subjectRow['id'], $semester, $mandatory]);
        $this->audit($pdo, $request, 'masters', 'section_subject_saved', 'erp_section_subject', $sectionId . ':' . $subjectRow['id'], [
            'sectionId' => $sectionId,
            'subjectCode' => $subjectCode,
            'semester' => $semester,
            'mandatory' => $mandatory,
        ]);
        return $this->json($response, ['data' => [
            'section_id' => $sectionId,
            'subject_id' => (int) $subjectRow['id'],
            'semester_no' => $semester,
            'is_mandatory' => $mandatory,
            'code' => $subjectCode,
            'name' => (string) $subjectRow['name'],
        ]], 201);
    }

    public function saveMasterFee(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only admin or accountant can manage fees'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $classId = (int) ($body['classId'] ?? 0);
        $rawName = trim((string) ($body['name'] ?? ''));
        $course = trim((string) ($body['course'] ?? ''));
        $faculty = trim((string) ($body['faculty'] ?? ''));
        $nameParts = array_filter([$course, $faculty, $rawName]);
        $name = implode(' - ', $nameParts);
        $amount = (float) ($body['amount'] ?? 0);
        if ($classId <= 0 || $rawName === '' || $amount <= 0) {
            return $this->json($response, ['error' => 'Class, fee head and amount are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $feePlanId = $this->upsertClassFeePlan($pdo, $classId, $name, $amount, date('Y-m-d'));
        $this->audit($pdo, $request, 'masters', 'fee_saved', 'erp_fee_plans', (string) $feePlanId, ['classId' => $classId, 'name' => $name, 'amount' => $amount]);
        return $this->masters($request, $response)->withStatus(201);
    }

    public function saveMasterRoute(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can manage routes'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $routeCode = strtoupper(trim((string) ($body['routeCode'] ?? '')));
        $monthlyFee = (float) ($body['monthlyFee'] ?? 0);
        if ($name === '' || $routeCode === '') {
            return $this->json($response, ['error' => 'Route name and route code are required'], 422);
        }
        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $st = $pdo->prepare(
            'INSERT INTO erp_transport_routes (institution_id, name, route_code, monthly_fee)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), monthly_fee = VALUES(monthly_fee)'
        );
        $st->execute([$institutionId, $name, $routeCode, $monthlyFee]);
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $lookup = $pdo->prepare('SELECT id FROM erp_transport_routes WHERE institution_id = ? AND route_code = ?');
            $lookup->execute([$institutionId, $routeCode]);
            $id = (int) $lookup->fetchColumn();
        }
        $this->audit($pdo, $request, 'masters', 'route_saved', 'erp_transport_route', (string) $id, ['name' => $name, 'routeCode' => $routeCode, 'monthlyFee' => $monthlyFee]);
        return $this->json($response, ['data' => ['id' => $id, 'name' => $name, 'route_code' => $routeCode, 'monthly_fee' => number_format($monthlyFee, 2, '.', '')]], 201);
    }

    public function saveMasterHostel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can manage hostels'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $hostelType = strtolower(trim((string) ($body['hostelType'] ?? 'boys')));
        if (!in_array($hostelType, ['boys', 'girls', 'staff', 'mixed'], true)) {
            $hostelType = 'mixed';
        }
        if ($name === '') {
            return $this->json($response, ['error' => 'Hostel name is required'], 422);
        }
        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $st = $pdo->prepare('INSERT INTO erp_hostels (institution_id, name, hostel_type) VALUES (?, ?, ?)');
        $st->execute([$institutionId, $name, $hostelType]);
        $id = (int) $pdo->lastInsertId();
        $this->audit($pdo, $request, 'masters', 'hostel_saved', 'erp_hostel', (string) $id, ['name' => $name, 'hostelType' => $hostelType]);
        return $this->json($response, ['data' => ['id' => $id, 'name' => $name, 'hostel_type' => $hostelType]], 201);
    }

    public function saveMasterDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can manage documents'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $purpose = trim((string) ($body['purpose'] ?? 'Admission'));
        $requirement = trim((string) ($body['requirement'] ?? 'Required'));
        $appliesTo = trim((string) ($body['appliesTo'] ?? 'All students'));
        $condition = trim((string) ($body['condition'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'Document name is required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $id = 'DOC-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $institutionId = $this->activeInstitutionId($pdo);
        $payload = ['purpose' => $purpose, 'requirement' => $requirement, 'appliesTo' => $appliesTo, 'condition' => $condition, 'notes' => $notes] + $this->activeAcademicYearMeta($pdo, $institutionId);
        $st = $pdo->prepare(
            'INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $userId = $request->getAttribute('user_id');
        $st->execute([
            $id,
            $userId !== null ? (int) $userId : null,
            'Document master',
            $name,
            strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '-', $name), 0, 40)),
            'Active',
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $this->audit($pdo, $request, 'masters', 'document_saved', 'erp_saved_records', $id, $payload);
        return $this->json($response, ['data' => ['id' => $id, 'name' => $name, 'purpose' => $purpose, 'requirement' => $requirement, 'appliesTo' => $appliesTo, 'condition' => $condition, 'notes' => $notes]], 201);
    }

    public function saveRecord(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $module = trim((string) ($body['module'] ?? 'General')) ?: 'General';
        $status = trim((string) ($body['status'] ?? 'Draft')) ?: 'Draft';
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'Record name is required'], 422);
        }

        $pdo = $this->db->pdo();
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', substr($module, 0, 12))) . '-' . date('His');
        }
        $recordId = 'REC-' . date('Ymd-His');
        $this->ensureSavedRecordsSchema($pdo);
        $userId = $request->getAttribute('user_id');
        $userId = $userId !== null ? (int) $userId : null;
        $createdAt = date(DATE_ATOM);
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $st = $pdo->prepare(
            'INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$recordId, $userId, $module, $name, $code, $status, $payload]);
        $this->audit($pdo, $request, $module, strtolower($status) === 'draft' ? 'draft_saved' : 'record_saved', 'erp_record', $recordId, [
            'name' => $name,
            'code' => $code,
            'status' => $status,
            'payload' => $body,
        ]);

        return $this->json($response, [
            'data' => [
                'id' => $recordId,
                'module' => $module,
                'name' => $name,
                'code' => $code,
                'status' => $status,
                'savedAt' => $createdAt,
                'payload' => $body,
            ],
        ], 201);
    }

    public function savedRecords(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $status = trim((string) ($request->getQueryParams()['status'] ?? ''));
        $module = trim((string) ($request->getQueryParams()['module'] ?? ''));
        $sql = 'SELECT r.*, u.display_name, u.email FROM erp_saved_records r LEFT JOIN users u ON u.id = r.user_id WHERE 1=1';
        $params = [];
        if ($status !== '') {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        if ($module !== '') {
            $sql .= ' AND r.module = ?';
            $params[] = $module;
        }
        $sql .= ' ORDER BY r.created_at DESC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = array_map(fn (array $row) => $this->savedRecordRow($row), $st->fetchAll());
        return $this->json($response, ['data' => $rows]);
    }

    public function reviewRecord(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can review teacher records'], 403);
        }
        $id = (string) ($args['id'] ?? '');
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $status = trim((string) ($body['status'] ?? 'Approved')) ?: 'Approved';
        $note = trim((string) ($body['note'] ?? '')) ?: null;
        if (!in_array($status, ['Approved', 'Rejected', 'Needs correction', 'Pending admin sync'], true)) {
            return $this->json($response, ['error' => 'Invalid review status'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $reviewerId = $request->getAttribute('user_id');
        $reviewerId = $reviewerId !== null ? (int) $reviewerId : null;
        $st = $pdo->prepare('UPDATE erp_saved_records SET status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?');
        $st->execute([$status, $reviewerId, $note, $id]);
        if ($st->rowCount() === 0) {
            return $this->json($response, ['error' => 'Record not found'], 404);
        }
        $this->audit($pdo, $request, 'records', 'reviewed', 'erp_saved_record', $id, ['status' => $status, 'note' => $note]);
        $row = $pdo->prepare('SELECT r.*, u.display_name, u.email FROM erp_saved_records r LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ?');
        $row->execute([$id]);
        return $this->json($response, ['data' => $this->savedRecordRow($row->fetch())]);
    }

    public function finance(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        return $this->json($response, ['data' => $this->financePayload($pdo)]);
    }

    public function collectFeePayment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can collect fee payments'], 403);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $studentId = (int) ($body['studentId'] ?? 0);
        $amount = (float) ($body['amount'] ?? 0);
        $feeHead = trim((string) ($body['feeHead'] ?? 'Fee collection')) ?: 'Fee collection';
        $action = (string) ($body['action'] ?? 'collect_payment');
        $method = strtolower(str_replace(' ', '_', trim((string) ($body['method'] ?? 'cash')) ?: 'cash'));
        $reference = trim((string) ($body['reference'] ?? '')) ?: null;
        $paidOn = date('Y-m-d H:i:s');

        if ($studentId <= 0) {
            return $this->json($response, ['error' => 'Student selection is required before collecting fees'], 422);
        }
        if ($amount <= 0) {
            return $this->json($response, ['error' => 'Amount received must be greater than zero'], 422);
        }

        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $pdo->beginTransaction();
        try {
            if ($action === 'add_balance') {
                $invoice = $this->createFeeInvoiceForStudent($pdo, $studentId, $feeHead, $amount);
                $this->audit($pdo, $request, 'finance', 'balance_added', 'erp_fee_invoices', (string) $invoice['invoice_no'], ['student_id' => $studentId, 'fee_head' => $feeHead, 'amount' => $amount]);
                $pdo->commit();
                return $this->json($response, [
                    'data' => [
                        'payment' => [
                            'receipt_no' => null,
                            'amount' => 0,
                            'method' => null,
                            'paid_at' => null,
                            'reference_no' => null,
                            'student_id' => $studentId,
                            'fee_head' => $feeHead,
                            'balance_after' => $amount,
                            'invoice_no' => $invoice['invoice_no'],
                        ],
                        'finance' => $this->financePayload($pdo),
                    ],
                ], 201);
            }

            $invoice = $this->openInvoiceForStudent($pdo, $studentId, $feeHead);
            if (!$invoice) {
                $invoice = $this->createFeeInvoiceForStudent($pdo, $studentId, $feeHead, $amount);
            }

            $balance = max((float) $invoice['amount'] - (float) $invoice['discount_amount'] - (float) $invoice['paid_amount'], 0);
            $posted = min($amount, $balance > 0 ? $balance : $amount);
            $receiptNo = 'RCT-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_payments') + 1), 4, '0', STR_PAD_LEFT);

            $payment = $pdo->prepare('INSERT INTO erp_fee_payments (invoice_id, receipt_no, fee_head, amount, method, paid_at, reference_no) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $payment->execute([(int) $invoice['id'], $receiptNo, $feeHead, $posted, $method, $paidOn, $reference]);

            $newPaid = (float) $invoice['paid_amount'] + $posted;
            $totalDue = (float) $invoice['amount'] - (float) $invoice['discount_amount'];
            $status = $newPaid >= $totalDue ? 'paid' : 'partial';
            $pdo->prepare('UPDATE erp_fee_invoices SET paid_amount = ?, status = ? WHERE id = ?')->execute([$newPaid, $status, (int) $invoice['id']]);
            $this->audit($pdo, $request, 'finance', 'payment_collected', 'erp_fee_payments', $receiptNo, ['student_id' => $studentId, 'fee_head' => $feeHead, 'amount' => $posted, 'status' => $status]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->json($response, [
            'data' => [
                'payment' => [
                    'receipt_no' => $receiptNo,
                    'amount' => $posted,
                    'method' => $method,
                    'paid_at' => $paidOn,
                    'reference_no' => $reference,
                    'student_id' => $studentId,
                    'fee_head' => $feeHead,
                    'balance_after' => max($totalDue - $newPaid, 0),
                    'fee_head_total' => $totalDue,
                    'fee_head_paid' => $newPaid,
                    'total_balance_after' => $this->scalarFloat($pdo, 'SELECT COALESCE(SUM(amount - discount_amount - paid_amount), 0) FROM erp_fee_invoices WHERE student_id = ' . $studentId),
                ],
                'finance' => $this->financePayload($pdo),
            ],
        ], 201);
    }

    public function createFeeHead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can manage fee heads'], 403);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $classId = (int) ($body['classId'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);
        $dueOn = trim((string) ($body['dueOn'] ?? '')) ?: date('Y-m-d');

        if ($classId <= 0 || $name === '' || $amount <= 0) {
            return $this->json($response, ['error' => 'Class, payment head and amount are required'], 422);
        }

        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $institutionId = (int) $pdo->query('SELECT institution_id FROM erp_classes WHERE id = ' . $classId . ' LIMIT 1')->fetchColumn();
        if ($institutionId <= 0) {
            return $this->json($response, ['error' => 'Selected class was not found'], 404);
        }
        $academicYearId = $this->activeAcademicYearId($pdo, $institutionId);

        $st = $pdo->prepare(
            'INSERT INTO erp_fee_plans (institution_id, academic_year_id, class_id, name, amount, due_on)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_on = VALUES(due_on)'
        );
        $st->execute([$institutionId, $academicYearId, $classId, $name, $amount, $dueOn]);
        $this->audit($pdo, $request, 'finance', 'fee_head_saved', 'erp_fee_plans', $name, ['class_id' => $classId, 'amount' => $amount, 'due_on' => $dueOn]);

        return $this->json($response, ['data' => $this->financePayload($pdo)], 201);
    }

    public function assignClassFees(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can assign class fees'], 403);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $classId = (int) ($body['classId'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);
        $dueOn = trim((string) ($body['dueOn'] ?? '')) ?: date('Y-m-d');

        if ($classId <= 0 || $name === '' || $amount <= 0) {
            return $this->json($response, ['error' => 'Class, fee name and amount are required'], 422);
        }

        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $pdo->beginTransaction();
        try {
            $feePlanId = $this->upsertClassFeePlan($pdo, $classId, $name, $amount, $dueOn);
            $applied = $this->applyFeePlanToClassStudents($pdo, $classId, $feePlanId, $amount, $dueOn);
            $this->audit($pdo, $request, 'finance', 'class_fee_assigned', 'erp_fee_plans', (string) $feePlanId, ['class_id' => $classId, 'fee_head' => $name, 'amount' => $amount, 'students' => $applied]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->json($response, ['data' => $this->financePayload($pdo), 'appliedStudents' => $applied], 201);
    }

    private function financePayload(PDO $pdo): array
    {
        $this->ensureFinanceSchema($pdo);
        $classes = $pdo->query('SELECT id, name FROM erp_classes ORDER BY level_order, name')->fetchAll();
        $students = $pdo->query(
            "SELECT s.id, s.admission_no, CONCAT(s.first_name, ' ', s.last_name) AS name,
                    s.class_id, s.section_id, c.name AS class_name, sec.name AS section_name
             FROM erp_students s
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_sections sec ON sec.id = s.section_id
             WHERE s.status = 'active'
             ORDER BY c.level_order, sec.name, s.first_name, s.last_name"
        )->fetchAll();
        $feeHeads = $pdo->query(
            "SELECT fp.id, fp.class_id, c.name AS class_name, fp.name, fp.amount, fp.due_on
             FROM erp_fee_plans fp
             LEFT JOIN erp_classes c ON c.id = fp.class_id
             ORDER BY c.level_order, fp.name"
        )->fetchAll();
        $invoices = $pdo->query(
            "SELECT i.id, i.invoice_no, i.amount, i.discount_amount, i.paid_amount, i.status, i.due_on,
                    s.id AS student_id, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.admission_no, s.class_id, c.name AS class_name,
                    f.name AS fee_plan
             FROM erp_fee_invoices i
             JOIN erp_students s ON s.id = i.student_id
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_fee_plans f ON f.id = i.fee_plan_id
             ORDER BY i.due_on, i.id"
        )->fetchAll();
        $payments = $pdo->query(
            "SELECT p.receipt_no, COALESCE(p.fee_head, f.name) AS fee_head, p.amount, p.method, p.paid_at, p.reference_no, i.invoice_no,
                    s.id AS student_id, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.admission_no, c.name AS class_name,
                    (i.amount - i.discount_amount) AS fee_head_total,
                    i.paid_amount AS fee_head_paid,
                    (i.amount - i.discount_amount - i.paid_amount) AS balance_after,
                    (
                        SELECT COALESCE(SUM(ii.amount - ii.discount_amount - ii.paid_amount), 0)
                        FROM erp_fee_invoices ii
                        WHERE ii.student_id = s.id
                    ) AS total_balance_after
             FROM erp_fee_payments p
             JOIN erp_fee_invoices i ON i.id = p.invoice_id
             JOIN erp_students s ON s.id = i.student_id
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_fee_plans f ON f.id = i.fee_plan_id
             ORDER BY p.paid_at DESC"
        )->fetchAll();

        $billed = $this->scalarFloat($pdo, 'SELECT COALESCE(SUM(amount - discount_amount), 0) FROM erp_fee_invoices');
        $collected = $this->scalarFloat($pdo, 'SELECT COALESCE(SUM(paid_amount), 0) FROM erp_fee_invoices');

        return [
            'summary' => [
                'billed' => $billed,
                'collected' => $collected,
                'outstanding' => max($billed - $collected, 0),
                'receipts' => count($payments),
            ],
            'classes' => $classes,
            'students' => $students,
            'feeHeads' => $feeHeads,
            'invoices' => $invoices,
            'payments' => $payments,
        ];
    }

    private function openInvoiceForStudent(PDO $pdo, int $studentId, ?string $feeHead = null): ?array
    {
        if ($feeHead !== null && trim($feeHead) !== '') {
            $st = $pdo->prepare(
                "SELECT i.*
                 FROM erp_fee_invoices i
                 JOIN erp_fee_plans f ON f.id = i.fee_plan_id
                 WHERE i.student_id = ? AND f.name = ? AND i.status IN ('due','partial','overdue')
                 ORDER BY i.due_on, i.id
                 LIMIT 1"
            );
            $st->execute([$studentId, $feeHead]);
            $invoice = $st->fetch();
            if ($invoice) {
                return $invoice;
            }
            return null;
        }
        $st = $pdo->prepare("SELECT * FROM erp_fee_invoices WHERE student_id = ? AND status IN ('due','partial','overdue') ORDER BY due_on, id LIMIT 1");
        $st->execute([$studentId]);
        $invoice = $st->fetch();
        return $invoice ?: null;
    }

    private function ensureFinanceSchema(PDO $pdo): void
    {
        $st = $pdo->query("SHOW COLUMNS FROM erp_fee_payments LIKE 'fee_head'");
        if (!$st->fetch()) {
            $pdo->exec('ALTER TABLE erp_fee_payments ADD COLUMN fee_head VARCHAR(160) NULL AFTER receipt_no');
        }
    }

    private function ensureSavedRecordsSchema(PDO $pdo): void
    {
        $exists = $pdo->query("SHOW TABLES LIKE 'erp_saved_records'")->fetchColumn();
        if ($exists) {
            return;
        }
        $pdo->exec(
            "CREATE TABLE erp_saved_records (
              id VARCHAR(80) NOT NULL PRIMARY KEY,
              user_id INT UNSIGNED NULL,
              module VARCHAR(120) NOT NULL,
              name VARCHAR(190) NOT NULL,
              code VARCHAR(120) NOT NULL,
              status VARCHAR(80) NOT NULL,
              payload_json LONGTEXT NULL,
              reviewed_by_user_id INT UNSIGNED NULL,
              reviewed_at DATETIME NULL,
              review_note VARCHAR(500) NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_erp_saved_records_status (status, created_at),
              KEY idx_erp_saved_records_module (module, created_at),
              CONSTRAINT fk_erp_saved_record_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
              CONSTRAINT fk_erp_saved_record_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function savedRecordRow(array|false $row): array
    {
        if (!$row) {
            return [];
        }
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
        return [
            'id' => (string) $row['id'],
            'module' => (string) $row['module'],
            'name' => (string) $row['name'],
            'code' => (string) $row['code'],
            'status' => (string) $row['status'],
            'savedAt' => (string) $row['created_at'],
            'submittedBy' => (string) (($row['display_name'] ?? '') ?: ($row['email'] ?? 'System')),
            'reviewedAt' => $row['reviewed_at'] ?? null,
            'reviewNote' => $row['review_note'] ?? null,
            'payload' => $payload,
        ];
    }

    private function upsertClassFeePlan(PDO $pdo, int $classId, string $name, float $amount, string $dueOn): int
    {
        $class = $pdo->prepare('SELECT institution_id FROM erp_classes WHERE id = ? LIMIT 1');
        $class->execute([$classId]);
        $institutionId = (int) $class->fetchColumn();
        if ($institutionId <= 0) {
            throw new \RuntimeException('Selected class was not found');
        }
        $academicYearId = $this->activeAcademicYearId($pdo, $institutionId);
        $st = $pdo->prepare(
            'INSERT INTO erp_fee_plans (institution_id, academic_year_id, class_id, name, amount, due_on)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_on = VALUES(due_on), id = LAST_INSERT_ID(id)'
        );
        $st->execute([$institutionId, $academicYearId, $classId, $name, $amount, $dueOn]);
        return (int) $pdo->lastInsertId();
    }

    private function applyFeePlanToClassStudents(PDO $pdo, int $classId, int $feePlanId, float $amount, string $dueOn): int
    {
        $students = $pdo->prepare("SELECT id FROM erp_students WHERE class_id = ? AND status = 'active'");
        $students->execute([$classId]);
        $applied = 0;
        foreach ($students->fetchAll() as $student) {
            $studentId = (int) $student['id'];
            $existing = $pdo->prepare('SELECT id, paid_amount FROM erp_fee_invoices WHERE student_id = ? AND fee_plan_id = ? LIMIT 1');
            $existing->execute([$studentId, $feePlanId]);
            $invoice = $existing->fetch();
            if ($invoice) {
                $paid = (float) $invoice['paid_amount'];
                $status = $paid >= $amount ? 'paid' : ($paid > 0 ? 'partial' : 'due');
                $pdo->prepare('UPDATE erp_fee_invoices SET amount = ?, due_on = ?, status = ? WHERE id = ?')
                    ->execute([$amount, $dueOn, $status, (int) $invoice['id']]);
            } else {
                $this->createInvoiceForFeePlan($pdo, $studentId, $feePlanId, $amount, $dueOn);
            }
            $applied++;
        }
        return $applied;
    }

    private function createInvoiceForFeePlan(PDO $pdo, int $studentId, int $feePlanId, float $amount, string $dueOn): int
    {
        $invoiceNo = 'INV-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_invoices') + 1), 4, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO erp_fee_invoices (student_id, fee_plan_id, invoice_no, amount, discount_amount, paid_amount, status, due_on) VALUES (?, ?, ?, ?, 0, 0, ?, ?)')
            ->execute([$studentId, $feePlanId, $invoiceNo, $amount, 'due', $dueOn]);
        return (int) $pdo->lastInsertId();
    }

    private function createFeeInvoiceForStudent(PDO $pdo, int $studentId, string $feeHead, float $amount): array
    {
        $st = $pdo->prepare(
            'SELECT s.class_id, s.admission_no, s.institution_id, s.academic_year_id, fp.id AS fee_plan_id
             FROM erp_students s
             LEFT JOIN erp_fee_plans fp ON fp.class_id = s.class_id AND fp.name = ?
             WHERE s.id = ?
             LIMIT 1'
        );
        $st->execute([$feeHead, $studentId]);
        $student = $st->fetch();
        if (!$student) {
            throw new \RuntimeException('No fee plan available for selected student');
        }
        $feePlanId = (int) ($student['fee_plan_id'] ?? 0);
        if ($feePlanId <= 0) {
            $pdo->prepare('INSERT INTO erp_fee_plans (institution_id, academic_year_id, class_id, name, amount, due_on) VALUES (?, ?, ?, ?, ?, CURDATE())')
                ->execute([(int) $student['institution_id'], (int) $student['academic_year_id'], (int) $student['class_id'], $feeHead, $amount]);
            $feePlanId = (int) $pdo->lastInsertId();
        }
        $invoiceNo = 'INV-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_invoices') + 1), 4, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO erp_fee_invoices (student_id, fee_plan_id, invoice_no, amount, discount_amount, paid_amount, status, due_on) VALUES (?, ?, ?, ?, 0, 0, ?, CURDATE())')
            ->execute([$studentId, $feePlanId, $invoiceNo, $amount, 'due']);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'amount' => $amount,
            'discount_amount' => 0,
            'paid_amount' => 0,
            'invoice_no' => $invoiceNo,
            'fee_head' => $feeHead,
        ];
    }

    private function admissionRow(array $row): array
    {
        $stage = (string) $row['stage'];
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        $subjects = is_array($details['subjects'] ?? null) ? $details['subjects'] : [];
        return [
            'id' => 'APP-2026-' . str_pad((string) $row['id'], 4, '0', STR_PAD_LEFT),
            'numericId' => (int) $row['id'],
            'name' => (string) $row['applicant_name'],
            'guardian' => (string) $row['guardian_name'],
            'phone' => (string) $row['phone'],
            'email' => (string) ($row['email'] ?? ''),
            'className' => (string) $row['class_name'],
            'stage' => $this->displayStage($stage),
            'source' => (string) ($row['source'] ?? 'Website'),
            'score' => (float) ($row['score'] ?? 0),
            'documents' => $this->documentCountForStage($stage),
            'feeStatus' => $this->feeStatusForStage($stage),
            'feeTotal' => $stage === 'offer' ? 45000 : ($stage === 'fee_paid' || $stage === 'enrolled' ? 45000 : 0),
            'feePaid' => $stage === 'fee_paid' || $stage === 'enrolled' ? 45000 : 0,
            'feeBalance' => $stage === 'offer' ? 45000 : 0,
            'aadharNo' => (string) ($row['aadhar_no'] ?? ''),
            'followUpAt' => (string) ($row['follow_up_at'] ?? ''),
            'followUpNote' => (string) ($row['follow_up_note'] ?? ''),
            'details' => $details,
            'subjects' => $subjects,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function findAdmission(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare(
            "SELECT a.id, a.applicant_name, a.guardian_name, a.phone, a.email, a.stage, a.score, a.source,
                    a.aadhar_no, a.follow_up_at, a.follow_up_note, a.details_json,
                    c.name AS class_name, a.created_at
             FROM erp_admission_applications a
             JOIN erp_classes c ON c.id = a.target_class_id
             WHERE a.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new \RuntimeException('Admission row missing after write');
        }
        return $this->admissionRow($row);
    }

    private function admissionReportRows(PDO $pdo): array
    {
        return $pdo->query("SELECT c.name AS label, COUNT(*) AS total, SUM(a.stage IN ('offer','fee_paid','enrolled')) AS converted FROM erp_admission_applications a JOIN erp_classes c ON c.id = a.target_class_id GROUP BY c.name ORDER BY c.name")->fetchAll();
    }

    private function financeReportRows(PDO $pdo): array
    {
        return $pdo->query("SELECT c.name AS label, SUM(i.amount - i.discount_amount) AS billed, SUM(i.paid_amount) AS collected, SUM(i.amount - i.discount_amount - i.paid_amount) AS outstanding FROM erp_fee_invoices i JOIN erp_students s ON s.id = i.student_id JOIN erp_classes c ON c.id = s.class_id GROUP BY c.name ORDER BY c.name")->fetchAll();
    }

    private function attendanceReportRows(PDO $pdo): array
    {
        return $pdo->query("SELECT sec.name AS label, COUNT(*) AS total, SUM(r.status = 'present') AS present, SUM(r.status IN ('absent','late')) AS risk FROM erp_attendance_records r JOIN erp_attendance_sessions ses ON ses.id = r.session_id JOIN erp_sections sec ON sec.id = ses.section_id GROUP BY sec.name ORDER BY sec.name")->fetchAll();
    }

    private function userReportRows(PDO $pdo): array
    {
        return $pdo->query('SELECT role AS label, COUNT(*) AS total FROM users GROUP BY role ORDER BY role')->fetchAll();
    }

    private function stageCounts(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT stage, COUNT(*) AS n FROM erp_admission_applications GROUP BY stage')->fetchAll();
        $counts = array_fill_keys(array_map(fn (string $s): string => $this->displayStage($s), self::STAGES), 0);
        foreach ($rows as $row) {
            $counts[$this->displayStage((string) $row['stage'])] = (int) $row['n'];
        }
        return $counts;
    }

    private function conversionRate(PDO $pdo): int
    {
        $total = max($this->countTable($pdo, 'erp_admission_applications'), 1);
        $converted = $this->scalarInt($pdo, "SELECT COUNT(*) FROM erp_admission_applications WHERE stage IN ('offer','fee_paid','enrolled')");
        return (int) round(($converted / $total) * 100);
    }

    private function displayStage(string $stage): string
    {
        return match ($stage) {
            'fee_paid' => 'Fee paid',
            default => ucfirst(str_replace('_', ' ', $stage)),
        };
    }

    private function storageStage(string $stage): string
    {
        return strtolower(str_replace(' ', '_', $stage));
    }

    private function documentCountForStage(string $stage): int
    {
        return match ($stage) {
            'enquiry' => 0,
            'application' => 2,
            'screening' => 4,
            'offer' => 5,
            'fee_paid', 'enrolled' => 6,
            default => 0,
        };
    }

    private function feeStatusForStage(string $stage): string
    {
        return match ($stage) {
            'offer' => 'Invoice generated',
            'fee_paid', 'enrolled' => 'Paid',
            default => 'Not generated',
        };
    }

    private function activeInstitutionId(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT id FROM erp_institutions ORDER BY id LIMIT 1')->fetchColumn();
    }

    private function activeAcademicYearId(PDO $pdo, int $institutionId): int
    {
        $st = $pdo->prepare('SELECT id FROM erp_academic_years WHERE institution_id = ? ORDER BY is_active DESC, starts_on DESC LIMIT 1');
        $st->execute([$institutionId]);
        return (int) $st->fetchColumn();
    }

    private function activeAcademicYearMeta(PDO $pdo, int $institutionId): array
    {
        $st = $pdo->prepare('SELECT id, name FROM erp_academic_years WHERE institution_id = ? ORDER BY is_active DESC, starts_on DESC LIMIT 1');
        $st->execute([$institutionId]);
        $row = $st->fetch() ?: [];
        return [
            'academicYearId' => (int) ($row['id'] ?? 0),
            'academicYear' => (string) ($row['name'] ?? ''),
        ];
    }

    private function classIdByName(PDO $pdo, int $institutionId, string $name): int
    {
        $name = $this->normalizeClassName($name);
        $st = $pdo->prepare('SELECT id FROM erp_classes WHERE institution_id = ? AND name = ? LIMIT 1');
        $st->execute([$institutionId, $name]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $st = $pdo->prepare('SELECT id FROM erp_classes WHERE institution_id = ? AND REPLACE(REPLACE(name, \'.\', \'\'), \' \', \'\') = REPLACE(REPLACE(?, \'.\', \'\'), \' \', \'\') LIMIT 1');
        $st->execute([$institutionId, $name]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $st = $pdo->prepare('SELECT id FROM erp_classes WHERE institution_id = ? ORDER BY level_order, id LIMIT 1');
        $st->execute([$institutionId]);
        return (int) $st->fetchColumn();
    }

    private function normalizeClassName(string $name): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $clean = str_ireplace(['B. Sc.', 'B.Sc.', 'B.Sc'], 'BSc', $clean);
        return $clean !== '' ? $clean : 'BA Year 1';
    }

    private function defaultSectionIdForClass(PDO $pdo, int $classId): int
    {
        if ($classId <= 0) {
            return 0;
        }
        $st = $pdo->prepare('SELECT id FROM erp_sections WHERE class_id = ? ORDER BY id LIMIT 1');
        $st->execute([$classId]);
        return (int) $st->fetchColumn();
    }

    private function topSourceMetric(PDO $pdo): string
    {
        $st = $pdo->query('SELECT source, COUNT(*) AS n FROM erp_admission_applications GROUP BY source ORDER BY n DESC LIMIT 1');
        $row = $st->fetch();
        if (!$row) {
            return 'No leads yet';
        }
        return ($row['source'] ?: 'Unknown') . ' leads ' . (int) $row['n'];
    }

    private function roleSlug(string $role): string
    {
        return match (strtolower($role)) {
            'super admin' => 'admin',
            'parent portal' => 'parent',
            'student portal' => 'student',
            default => strtolower(str_replace(' ', '_', $role)),
        };
    }

    private function audit(PDO $pdo, ServerRequestInterface $request, string $module, string $action, string $entityType, string $entityId, array $meta = []): void
    {
        $userId = $request->getAttribute('user_id');
        $userId = $userId !== null ? (int) $userId : null;
        try {
            $institutionId = $this->activeInstitutionId($pdo);
            $meta += $this->activeAcademicYearMeta($pdo, $institutionId);
            $meta += ['userRole' => (string) $request->getAttribute('user_role')];
        } catch (\Throwable) {
            $meta += ['userRole' => (string) $request->getAttribute('user_role')];
        }
        $st = $pdo->prepare(
            'INSERT INTO erp_audit_logs (user_id, module, action, entity_type, entity_id, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$userId, $module, $action, $entityType, $entityId, json_encode($meta, JSON_THROW_ON_ERROR)]);
    }

    private function countTable(PDO $pdo, string $table): int
    {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function scalarInt(PDO $pdo, string $sql): int
    {
        return (int) $pdo->query($sql)->fetchColumn();
    }

    private function scalarFloat(PDO $pdo, string $sql): float
    {
        return (float) $pdo->query($sql)->fetchColumn();
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
