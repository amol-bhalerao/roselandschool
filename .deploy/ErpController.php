<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class ErpController
{
    private const STAGES = ['enquiry', 'application', 'screening', 'offer', 'fee_paid', 'enrolled'];
    private const STUDENT_PHOTO_MAX_BYTES = 3 * 1024 * 1024;
    private const ADMISSION_DOCUMENT_MAX_BYTES = 8 * 1024 * 1024;
    private const ADMISSION_DOCUMENT_TARGET_BYTES = 300 * 1024;

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
        $aadharRaw = trim((string) ($body['aadharNo'] ?? ($details['Aadhar No'] ?? $details['aadhar_no'] ?? '')));
        $aadhar = $aadharRaw !== '' ? (preg_replace('/\D+/', '', $aadharRaw) ?: null) : null;
        if ($aadhar !== null && strlen($aadhar) !== 12) {
            return $this->json($response, ['error' => 'Aadhaar number must be 12 digits'], 422);
        }
        if ($aadhar !== null) {
            $existing = $pdo->prepare(
                'SELECT id FROM erp_admission_applications
                 WHERE institution_id = ? AND target_class_id = ?
                   AND REPLACE(REPLACE(REPLACE(COALESCE(aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );
            $existing->execute([$institutionId, $classId, $aadhar]);
            $existingId = (int) ($existing->fetchColumn() ?: 0);
            if ($existingId > 0) {
                return $this->json($response, ['error' => 'Admission/enquiry already exists for this Aadhaar and class', 'data' => $this->findAdmission($pdo, $existingId)], 409);
            }
        }
        $st = $pdo->prepare(
            'INSERT INTO erp_admission_applications
             (institution_id, academic_year_id, target_class_id, applicant_name, guardian_name, phone, email, aadhar_no, follow_up_at, follow_up_note, details_json, stage, score, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stage = filter_var($body['directAdmission'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'application' : 'enquiry';
        if (empty($details['admission_status'])) {
            $details['admission_status'] = $stage === 'application' ? 'Admission form in progress' : 'Enquiry';
        }
        $st->execute([$institutionId, $yearId, $classId, $name, $guardian, $phone, $email, $aadhar, $followUpAt, $followUpNote, json_encode($details), $stage, 0, $source]);
        $id = (int) $pdo->lastInsertId();
        $this->audit($pdo, $request, 'admissions', 'created', 'erp_admission_applications', (string) $id, ['applicant' => $name]);
        return $this->json($response, ['data' => $this->findAdmission($pdo, $id)], 201);
    }

    public function createPublicAdmissionApplication(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $details = is_array($body['details'] ?? null) ? $body['details'] : $body;
        $isDraft = filter_var($body['draft'] ?? false, FILTER_VALIDATE_BOOLEAN);
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
        $abcId = strtoupper(trim((string) ($details['ABC ID'] ?? '')));
        if ($abcId !== '' && !preg_match('/^[A-Z0-9]{12,16}$/', $abcId)) {
            return $this->json($response, ['error' => 'ABC ID must be 12-16 letters/numbers'], 422);
        }
        $parentPhone = trim((string) ($details["Parent's/Guardian's Mobile Number"] ?? ''));
        if ($parentPhone !== '' && strlen($digitsOnly($parentPhone)) !== 10) {
            return $this->json($response, ['error' => 'Parent mobile number must be 10 digits'], 422);
        }
        $outOfMarks = (float) ($details['Out of Marks'] ?? 0);
        $obtainedMarks = (float) ($details['Obtained Marks'] ?? 0);
        if ($outOfMarks > 0 && $obtainedMarks > $outOfMarks) {
            return $this->json($response, ['error' => 'Obtained marks cannot be greater than total marks'], 422);
        }

        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $yearId = $this->activeAcademicYearId($pdo, $institutionId);
        $className = $this->normalizeClassName((string) ($details['Admission Class'] ?? $body['className'] ?? 'Class I'));
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
            'Admission To', 'Admission Class', 'Gender', 'Blood Group', 'Marital Status',
            'Mother Tongue', 'Religion', 'Country', 'State', 'District', 'Taluka',
            'Permanent Address Same as Correspondence Address', 'Nationality', 'Country of Citizenship',
            'Domicile Of State', 'Residential proof', 'Category', 'Economically Weaker Section (EWS)',
            'Permanent benchmark disabilities', 'Divyang', 'Claim reservation benefits', 'SSC Month',
            'HSC / XIth Month', 'Qualification Type', 'Name of Qualification', 'Qualification Status',
            'Board/University', 'Education from Foreign Board', 'Result Type',
            'Academic Gap', 'Dual Degree Interested',
            'Are you Employed or Self-Employed', 'Occupation of Guardian', 'Guardian from EBC',
            'Account Holder', 'Name changed after Passing qualifying examination',
        ];
        $selectLikeLookup = array_flip($selectLikeFields);
        $publicImageFields = ['student_photo_data_url' => true, 'declaration_photo_data_url' => true, 'student_signature_data_url' => true];
        foreach ($details as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (isset($publicImageFields[(string) $key])) {
                $cleanImage = trim($value);
                if ($cleanImage !== '' && !preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/i', $cleanImage)) {
                    return $this->json($response, ['error' => 'Only JPEG, PNG or WebP image is allowed for photograph and signature'], 415);
                }
                if (strlen($cleanImage) > 350000) {
                    return $this->json($response, ['error' => 'Photograph or signature image is too large. Please upload a smaller image.'], 413);
                }
                $details[$key] = $cleanImage;
                continue;
            }
            $isUnicodeNameField = str_contains((string) $key, 'Marathi') || str_contains((string) $key, 'Regional Language');
            $clean = $isUnicodeNameField
                ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '')
                : trim(preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');
            if (str_starts_with((string) $key, 'Document:')) {
                $details[$key] = match (strtolower($clean)) {
                    'submitted', 'with me', 'with me now', 'available', 'available with student' => 'Available with student',
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
        if ($isDraft && $name === '') {
            $name = 'DRAFT ' . substr(preg_replace('/\D+/', '', (string) ($details['Aadhar No'] ?? '')) ?: (string) time(), -4);
        }
        if (!$isDraft && ($name === '' || $phone === '')) {
            return $this->json($response, ['error' => 'Student name and mobile number are required'], 422);
        }
        if ($phone !== '' && !preg_match('/^[0-9+()\\-\\s]{10,20}$/', $phone)) {
            return $this->json($response, ['error' => 'Enter a valid mobile number'], 422);
        }
        $parentPhone = trim((string) ($details["Parent's/Guardian's Mobile Number"] ?? ''));
        $parentDigits = preg_replace('/\D+/', '', $parentPhone) ?: '';
        if ($parentPhone !== '' && strlen($parentDigits) !== 10) {
            return $this->json($response, ['error' => 'Parent mobile number must be 10 digits'], 422);
        }
        $aadharRaw = trim((string) ($details['Aadhar No'] ?? ''));
        $aadharDigits = preg_replace('/\D+/', '', $aadharRaw) ?: '';
        if ($aadharRaw !== '' && strlen($aadharDigits) !== 12) {
            return $this->json($response, ['error' => 'Aadhaar number must be 12 digits'], 422);
        }
        $abcId = strtoupper(trim((string) ($details['ABC ID'] ?? '')));
        if ($abcId !== '' && !preg_match('/^[A-Z0-9]{12,16}$/', $abcId)) {
            return $this->json($response, ['error' => 'ABC ID must be 12-16 letters/numbers'], 422);
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
        $className = $this->normalizeClassName((string) ($details['Admission Class'] ?? 'Class I'));
        $classId = $this->classIdByName($pdo, $institutionId, $className);
        $sectionId = $this->defaultSectionIdForClass($pdo, $classId);
        $activeYearMeta = $this->activeAcademicYearMeta($pdo, $institutionId);
        $details['Admission Class'] = $className;
        if (!empty($activeYearMeta['academicYear'])) {
            $details['Academic Year'] = (string) $activeYearMeta['academicYear'];
        }
        if ($sectionId > 0 && empty($details['Class Section Id'])) {
            $details['Class Section Id'] = (string) $sectionId;
        }
        $details['admission_status'] = $isDraft ? 'Admission form in progress' : 'Submitted from website - pending document verification';
        $details['public_website_admission_form'] = !$isDraft;

        if ($aadharDigits !== '') {
            $existing = $pdo->prepare(
                'SELECT id, stage, details_json FROM erp_admission_applications
                 WHERE institution_id = ? AND target_class_id = ?
                   AND REPLACE(REPLACE(REPLACE(COALESCE(aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );
            $existing->execute([$institutionId, $classId, $aadharDigits]);
            $existingRow = $existing->fetch() ?: null;
            $existingId = (int) ($existingRow['id'] ?? 0);
            if ($existingId > 0) {
                $editToken = trim((string) ($body['editToken'] ?? ''));
                $existingDetails = json_decode((string) ($existingRow['details_json'] ?? '{}'), true);
                $existingDetails = is_array($existingDetails) ? $existingDetails : [];
                $existingStatus = strtolower(trim((string) ($existingDetails['admission_status'] ?? '')));
                $existingStage = strtolower((string) ($existingRow['stage'] ?? ''));
                $existingSubmitted = (bool) ($existingDetails['public_website_admission_form'] ?? false)
                    || str_contains($existingStatus, 'submitted from website')
                    || str_contains($existingStatus, 'pending document verification')
                    || str_contains($existingStatus, 'document verified')
                    || str_contains($existingStatus, 'active admission')
                    || in_array($existingStage, ['offer', 'fee_paid', 'enrolled'], true);
                $existingLockedForPublicEdit = str_contains($existingStatus, 'document verified')
                    || str_contains($existingStatus, 'verified document')
                    || str_contains($existingStatus, 'active admission')
                    || str_contains($existingStatus, 'admission confirmed')
                    || in_array($existingStage, ['offer', 'fee_paid', 'enrolled'], true);
                if ($existingLockedForPublicEdit && !$this->verifyAdmissionEditToken($pdo, $existingId, $editToken)) {
                    if ($isDraft) {
                        $existingResponse = $this->publicAdmissionResponseFromRow($existingRow, $existingDetails);
                        $existingResponse['draftSkipped'] = true;
                        return $this->json($response, ['data' => $existingResponse]);
                    }
                    return $this->json($response, ['error' => 'This admission form is locked after document verification. Please contact the school office for corrections.'], 403);
                }
                $update = $pdo->prepare(
                    'UPDATE erp_admission_applications
                     SET applicant_name = ?, guardian_name = ?, phone = ?, email = ?, aadhar_no = ?, details_json = ?, stage = ?
                     WHERE id = ?'
                );
                $update->execute([
                    $name,
                    $middle !== '' ? $middle : 'Website admission form',
                    $phone,
                    trim((string) ($details['Email Id'] ?? '')) ?: null,
                    $aadharDigits,
                    json_encode($details),
                    $isDraft ? 'draft' : 'application',
                    $existingId,
                ]);
                $this->audit($pdo, $request, 'admissions', $isDraft ? 'public_admission_form_draft_saved' : 'public_admission_form_updated', 'erp_admission_applications', (string) $existingId, ['applicant' => $name, 'class' => $className]);
                return $this->json($response, ['data' => $this->publicAdmissionResponse($pdo, $existingId)], 200);
            }
        }

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
            $isDraft ? 'draft' : 'application',
            0,
            'Website admission form',
        ]);
        $id = (int) $pdo->lastInsertId();
        $applicationRef = 'RSL-' . date('Y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $details['Application Sr. No'] = $applicationRef;
        $details['Admission Form No'] = $applicationRef;
        $updateDetails = $pdo->prepare('UPDATE erp_admission_applications SET details_json = ? WHERE id = ?');
        $updateDetails->execute([json_encode($details), $id]);
        $this->audit($pdo, $request, 'admissions', $isDraft ? 'public_admission_form_draft_saved' : 'public_admission_form_submitted', 'erp_admission_applications', (string) $id, ['applicant' => $name]);
        return $this->json($response, ['data' => $this->publicAdmissionResponse($pdo, $id)], 201);
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
                $mappings[$classId] = ['course' => (string) ($payload['course'] ?? '')];
            }
        }
        $classNamesById = [];
        $publicClasses = [];
        foreach ($classes as $class) {
            $classId = (int) $class['id'];
            $classNamesById[$classId] = (string) $class['name'];
            $mapping = $mappings[$classId] ?? null;
            if (!$mapping || trim($mapping['course']) === '') {
                continue;
            }
            $publicClasses[] = ['id' => $classId, 'name' => (string) $class['name'], 'course' => $mapping['course'], 'level_order' => (int) $class['level_order']];
        }
        $courseMap = [];
        foreach ($publicClasses as $class) {
            $courseMap[$class['course']] = ['course' => $class['course']];
        }
        $this->ensureSubjectSelectionLimitSchema($pdo);
        $this->ensureDynamicSubjectPlanSchema($pdo);
        $subjectRows = $pdo->query('SELECT sec.class_id, sub.code, sub.name, sub.subject_type, ss.semester_no, ss.is_mandatory FROM erp_section_subjects ss JOIN erp_sections sec ON sec.id = ss.section_id JOIN erp_subjects sub ON sub.id = ss.subject_id ORDER BY sec.class_id, ss.semester_no, sub.name')->fetchAll();
        $limitRows = $pdo->query('SELECT sec.class_id, lim.section_id, lim.semester_no, lim.max_subjects FROM erp_section_subject_limits lim JOIN erp_sections sec ON sec.id = lim.section_id ORDER BY sec.class_id, lim.semester_no')->fetchAll();
        $subjectLimits = [];
        foreach ($limitRows as $row) {
            $classId = (int) $row['class_id'];
            $mapping = $mappings[$classId] ?? null;
            if (!$mapping || empty($classNamesById[$classId])) {
                continue;
            }
            $subjectLimits[] = [
                'classId' => $classId,
                'className' => $classNamesById[$classId],
                'sectionId' => (int) $row['section_id'],
                'semester' => (int) $row['semester_no'],
                'maxSubjects' => (int) $row['max_subjects'],
                'course' => $mapping['course'],
            ];
        }
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
            ];
        }
        $documentRows = $pdo->query("SELECT id, name, payload_json FROM erp_saved_records WHERE module = 'Document master' AND status = 'Active' ORDER BY created_at DESC")->fetchAll();
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
        $courseYearGroups = $this->normalizeCourseYearGroups($pdo->query('SELECT * FROM erp_course_year_subject_groups WHERE is_active = 1 ORDER BY course_name, year_name, sort_order, id')->fetchAll());
        $courseYearGroupSubjects = $pdo->query(
            'SELECT gs.group_id, g.course_name, g.year_name, g.group_key, gs.semester_no, gs.subject_id, gs.subject_family_key, gs.sort_order, gs.is_default, sub.code, sub.name, sub.subject_type
             FROM erp_course_year_group_subjects gs
             JOIN erp_course_year_subject_groups g ON g.id = gs.group_id
             JOIN erp_subjects sub ON sub.id = gs.subject_id
             ORDER BY g.course_name, g.year_name, g.group_key, gs.semester_no, gs.sort_order, sub.name'
        )->fetchAll();
        return $this->json($response, ['data' => ['courses' => array_values($courseMap), 'faculties' => [], 'classes' => $publicClasses, 'subjects' => $subjects, 'subjectLimits' => $subjectLimits, 'courseYearGroups' => $courseYearGroups, 'courseYearGroupSubjects' => $courseYearGroupSubjects, 'documents' => $documents]]);
    }

    public function publicSubjectSelection(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $classId = (int) ($request->getQueryParams()['classId'] ?? 0);
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $this->ensureDynamicSubjectPlanSchema($pdo);
        if ($classId <= 0) {
            return $this->json($response, ['error' => 'Class selection is required'], 422);
        }
        $class = $pdo->prepare('SELECT id, name FROM erp_classes WHERE id = ? LIMIT 1');
        $class->execute([$classId]);
        $classRow = $class->fetch();
        if (!$classRow) {
            return $this->json($response, ['error' => 'Class not found'], 404);
        }
        $mapping = $this->classCourseMapping($pdo, $classId);
        $course = (string) ($mapping['course'] ?? '');
        $yearName = (string) $classRow['name'];
        $groups = $this->courseYearSubjectSelectionPayload($pdo, $course, $yearName);
        return $this->json($response, ['data' => ['classId' => $classId, 'className' => $yearName, 'course' => $course, 'yearName' => $yearName, 'groups' => $groups]]);
    }

    public function findPublicAdmissionByAadhar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $aadhar = preg_replace('/\D+/', '', (string) ($args['aadhar'] ?? '')) ?: '';
        if (strlen($aadhar) !== 12) {
            return $this->json($response, ['error' => 'Aadhaar must be 12 digits'], 422);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT a.id, a.applicant_name, a.aadhar_no, a.details_json, a.stage, a.created_at, c.name AS class_name
             FROM erp_admission_applications a
             LEFT JOIN erp_classes c ON c.id = a.target_class_id
             WHERE REPLACE(REPLACE(REPLACE(COALESCE(a.aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(a.details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ?
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 1'
        );
        $st->execute([$aadhar]);
        $row = $st->fetch();
        if (!$row) {
            return $this->json($response, ['data' => null]);
        }
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        $details['Aadhar No'] = $aadhar;
        return $this->json($response, ['data' => $this->publicAdmissionResponseFromRow($row, $details)]);
    }

    public function findPublicAdmissionById(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $raw = (string) ($args['id'] ?? '');
        if (preg_match('/(?:APP|LBP|RSL)-\d{4}-(\d+)/i', $raw, $m)) {
            $id = (int) ltrim($m[1], '0');
        } else {
            $id = (int) preg_replace('/\D+/', '', $raw);
        }
        if ($id <= 0) {
            return $this->json($response, ['error' => 'Admission reference is required'], 422);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT a.id, a.applicant_name, a.aadhar_no, a.details_json, a.stage, a.created_at, c.name AS class_name
             FROM erp_admission_applications a
             LEFT JOIN erp_classes c ON c.id = a.target_class_id
             WHERE a.id = ?
             LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return $this->json($response, ['data' => null], 404);
        }
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        return $this->json($response, ['data' => $this->publicAdmissionResponseFromRow($row, $details)]);
    }

    public function requestPublicAdmissionEditOtp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $applicationId = trim((string) ($body['applicationId'] ?? ''));
        $aadhar = preg_replace('/\D+/', '', (string) ($body['aadharNo'] ?? '')) ?: '';
        $numericId = $this->admissionIdFromReference($applicationId);
        if ($numericId <= 0 || strlen($aadhar) !== 12) {
            return $this->json($response, ['error' => 'Application reference and 12 digit Aadhaar are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureAdmissionOtpSchema($pdo);
        $st = $pdo->prepare(
            'SELECT id, applicant_name, email, details_json
             FROM erp_admission_applications
             WHERE id = ? AND REPLACE(REPLACE(REPLACE(COALESCE(aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ?
             LIMIT 1'
        );
        $st->execute([$numericId, $aadhar]);
        $row = $st->fetch();
        if (!$row) {
            return $this->json($response, ['error' => 'Admission application was not found for this Aadhaar number'], 404);
        }
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        $email = strtolower(trim((string) ($details['Email Id'] ?? $row['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['error' => 'No valid registered email is saved for this admission form. Please contact school office.'], 422);
        }
        $settings = $this->loadEmailSettings($pdo);
        if (!$settings || (int) ($settings['is_enabled'] ?? 0) !== 1) {
            return $this->json($response, ['error' => 'Email OTP is not configured yet. Please ask admin to configure Gmail SMTP settings.'], 422);
        }
        $otp = (string) random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + 600);
        $insert = $pdo->prepare(
            'INSERT INTO erp_admission_edit_otps (admission_id, email, otp_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $insert->execute([(int) $row['id'], $email, password_hash($otp, PASSWORD_DEFAULT), $expiresAt]);
        $subject = 'Admission form edit OTP';
        $safeName = trim((string) ($row['applicant_name'] ?? 'Student'));
        $message = "Dear {$safeName},\n\nYour OTP to edit the admission form {$applicationId} is {$otp}.\nThis OTP is valid for 10 minutes.\n\nIf you did not request this, please ignore this message.";
        try {
            $this->sendConfiguredEmail($settings, $email, $subject, $message);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Could not send OTP email: ' . $e->getMessage()], 500);
        }
        return $this->json($response, ['data' => ['maskedEmail' => $this->maskEmail($email), 'expiresInMinutes' => 10]]);
    }

    public function verifyPublicAdmissionEditOtp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $applicationId = trim((string) ($body['applicationId'] ?? ''));
        $aadhar = preg_replace('/\D+/', '', (string) ($body['aadharNo'] ?? '')) ?: '';
        $otp = preg_replace('/\D+/', '', (string) ($body['otp'] ?? '')) ?: '';
        $numericId = $this->admissionIdFromReference($applicationId);
        if ($numericId <= 0 || strlen($aadhar) !== 12 || strlen($otp) !== 6) {
            return $this->json($response, ['error' => 'Application, Aadhaar and 6 digit OTP are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureAdmissionOtpSchema($pdo);
        $admission = $pdo->prepare(
            'SELECT id FROM erp_admission_applications
             WHERE id = ? AND REPLACE(REPLACE(REPLACE(COALESCE(aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ?
             LIMIT 1'
        );
        $admission->execute([$numericId, $aadhar]);
        if (!$admission->fetch()) {
            return $this->json($response, ['error' => 'Admission application was not found for this Aadhaar number'], 404);
        }
        $st = $pdo->prepare(
            'SELECT * FROM erp_admission_edit_otps
             WHERE admission_id = ? AND verified_at IS NULL AND expires_at >= NOW() AND attempts < 5
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$numericId]);
        $row = $st->fetch();
        if (!$row) {
            return $this->json($response, ['error' => 'OTP expired or not requested. Please send a new OTP.'], 422);
        }
        if (!password_verify($otp, (string) $row['otp_hash'])) {
            $pdo->prepare('UPDATE erp_admission_edit_otps SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $row['id']]);
            return $this->json($response, ['error' => 'Invalid OTP. Please check and try again.'], 422);
        }
        $editToken = bin2hex(random_bytes(24));
        $pdo->prepare(
            'UPDATE erp_admission_edit_otps
             SET verified_at = NOW(), edit_token_hash = ?, edit_token_expires_at = ?
             WHERE id = ?'
        )->execute([password_hash($editToken, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 1800), (int) $row['id']]);
        return $this->json($response, ['data' => ['verified' => true, 'editToken' => $editToken]]);
    }

    public function emailSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $this->ensureAdmissionOtpSchema($pdo);
        $settings = $this->loadEmailSettings($pdo) ?: [];
        return $this->json($response, ['data' => $this->emailSettingsResponse($settings)]);
    }

    public function saveEmailSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $this->ensureAdmissionOtpSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        $password = trim((string) ($body['smtpPassword'] ?? ''));
        $current = $this->loadEmailSettings($pdo) ?: [];
        $settings = [
            'smtp_host' => trim((string) ($body['smtpHost'] ?? 'smtp.gmail.com')) ?: 'smtp.gmail.com',
            'smtp_port' => (int) ($body['smtpPort'] ?? 587),
            'smtp_encryption' => strtolower(trim((string) ($body['smtpEncryption'] ?? 'tls'))) ?: 'tls',
            'smtp_username' => trim((string) ($body['smtpUsername'] ?? '')),
            'smtp_password' => $password !== '' ? $password : (string) ($current['smtp_password'] ?? ''),
            'from_email' => trim((string) ($body['fromEmail'] ?? $body['smtpUsername'] ?? '')),
            'from_name' => trim((string) ($body['fromName'] ?? 'ROSELAND SCHOOL Admissions')) ?: 'ROSELAND SCHOOL Admissions',
            'is_enabled' => filter_var($body['isEnabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
        if (!filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL) || !filter_var($settings['smtp_username'], FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['error' => 'Valid Gmail username and from email are required'], 422);
        }
        if ($settings['smtp_password'] === '') {
            return $this->json($response, ['error' => 'Google app password is required'], 422);
        }
        $st = $pdo->prepare(
            'INSERT INTO erp_email_settings
             (institution_id, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, from_email, from_name, is_enabled, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port), smtp_encryption = VALUES(smtp_encryption),
                smtp_username = VALUES(smtp_username), smtp_password = VALUES(smtp_password), from_email = VALUES(from_email),
                from_name = VALUES(from_name), is_enabled = VALUES(is_enabled), updated_at = NOW()'
        );
        $st->execute([
            $institutionId,
            $settings['smtp_host'],
            $settings['smtp_port'],
            $settings['smtp_encryption'],
            $settings['smtp_username'],
            $settings['smtp_password'],
            $settings['from_email'],
            $settings['from_name'],
            $settings['is_enabled'],
        ]);
        $this->audit($pdo, $request, 'settings', 'email_otp_settings_updated', 'erp_email_settings', (string) $institutionId, ['smtpUsername' => $settings['smtp_username']]);
        return $this->json($response, ['data' => $this->emailSettingsResponse($settings)]);
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
        $className = trim((string) ($body['className'] ?? 'Class I')) ?: 'Class I';
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
        $existing = $pdo->prepare(
            'SELECT id FROM erp_admission_applications
             WHERE institution_id = ? AND target_class_id = ? AND phone = ? AND stage IN ("enquiry", "application", "screening", "offer", "fee_paid", "enrolled")
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $existing->execute([$institutionId, $classId, $phone]);
        $existingId = (int) ($existing->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $this->json($response, ['error' => 'An enquiry/admission already exists for this student and class', 'data' => $this->findAdmission($pdo, $existingId)], 409);
        }
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
        if ($role !== 'clerk') {
            return $this->json($response, ['error' => 'Only clerk can validate admission documents'], 403);
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
        $uploadedDocuments = is_array($details['uploaded_documents'] ?? null) ? $details['uploaded_documents'] : [];
        $verified = is_array($body['verifiedDocuments'] ?? null) ? $body['verifiedDocuments'] : array_values(array_unique(array_filter(array_map(static fn (array $doc): string => (string) ($doc['name'] ?? ''), $uploadedDocuments))));
        $collected = is_array($body['collectedDocuments'] ?? null) ? $body['collectedDocuments'] : [];
        $pending = is_array($body['pendingDocuments'] ?? null) ? $body['pendingDocuments'] : [];
        $documentStatuses = is_array($body['documentStatuses'] ?? null) ? $body['documentStatuses'] : [];
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
            'document_statuses' => $documentStatuses,
            'result' => (string) ($body['result'] ?? 'Some documents pending'),
            'note' => (string) ($body['note'] ?? ''),
            'verified_by_role' => $role,
            'verified_at' => date('Y-m-d H:i:s'),
        ];
        $details['admission_status'] = empty($pending) ? 'Document verified' : 'Partially verified documents';
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
        $existingDetails = json_decode((string) ($app['details_json'] ?? '{}'), true) ?: [];
        $existingStatus = strtolower(trim((string) ($existingDetails['admission_status'] ?? '')));
        $submitted = false;
        if (!str_contains($existingStatus, 'in progress') && $existingStatus !== 'enquiry') {
            foreach (['verified admission form', 'submitted from website', 'pending document verification', 'document verified', 'verified document', 'partially verified', 'active admission', 'enrolled', 'fee paid'] as $needle) {
                if (str_contains($existingStatus, $needle)) {
                    $submitted = true;
                    break;
                }
            }
        }
        if (!$submitted && in_array((string) $app['stage'], ['fee_paid', 'enrolled'], true)) {
            $submitted = true;
        }
        if ($submitted && !in_array($role, ['admin', 'clerk', 'student'], true)) {
            return $this->json($response, ['error' => 'Submitted admission forms can be edited only by student, clerk and admin. Your role can view or print the form.'], 403);
        }
        $documentsVerified = str_contains($existingStatus, 'document verified') || str_contains($existingStatus, 'verified document');
        if ($documentsVerified && !in_array($role, ['admin', 'clerk', 'student'], true)) {
            return $this->json($response, ['error' => 'Admission form is locked after document verification. Only student, clerk and admin can edit it.'], 403);
        }

        $details = array_merge($existingDetails, $incomingDetails);
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
        if ($role === 'accountant' || !in_array((string) $app['stage'], ['fee_paid', 'enrolled'], true)) {
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
        $digitsOnly = static fn (string $value): string => preg_replace('/\D+/', '', $value) ?? '';
        if ($phone !== '' && strlen($digitsOnly($phone)) !== 10) {
            return $this->json($response, ['error' => 'Mobile number must be 10 digits'], 422);
        }
        $parentPhone = trim((string) ($incomingDetails["Parent's/Guardian's Mobile Number"] ?? $details["Parent's/Guardian's Mobile Number"] ?? ''));
        if ($parentPhone !== '' && strlen($digitsOnly($parentPhone)) !== 10) {
            return $this->json($response, ['error' => 'Parent mobile number must be 10 digits'], 422);
        }
        if ($aadhar !== null && strlen($digitsOnly($aadhar)) !== 12) {
            return $this->json($response, ['error' => 'Aadhaar number must be 12 digits'], 422);
        }
        $abcId = strtoupper(trim((string) ($incomingDetails['ABC ID'] ?? $details['ABC ID'] ?? '')));
        if ($abcId !== '' && !preg_match('/^[A-Z0-9]{12,16}$/', $abcId)) {
            return $this->json($response, ['error' => 'ABC ID must be 12-16 letters/numbers'], 422);
        }
        $nextStage = (string) $app['stage'] === 'enquiry' ? 'application' : (string) $app['stage'];
        $incomingStatus = trim((string) ($incomingDetails['admission_status'] ?? ''));
        $details['admission_status'] = $incomingStatus !== '' ? $incomingStatus : 'Verified admission form';

        $update = $pdo->prepare(
            'UPDATE erp_admission_applications
             SET target_class_id = ?, target_section_id = ?, applicant_name = ?, guardian_name = ?, phone = ?, aadhar_no = ?, details_json = ?, stage = ?
             WHERE id = ?'
        );
        $update->execute([$classId, $sectionId, $applicantName, $guardian, $phone, $aadhar, json_encode($details), $nextStage, $id]);
        $changedFields = [];
        foreach ($incomingDetails as $field => $newValue) {
            $oldValue = $existingDetails[$field] ?? null;
            if (json_encode($oldValue) !== json_encode($newValue)) {
                $changedFields[] = (string) $field;
            }
        }
        foreach ([
            'Applicant name' => [(string) $app['applicant_name'], $applicantName],
            'Guardian name' => [(string) $app['guardian_name'], $guardian],
            'Mobile no' => [(string) $app['phone'], $phone],
            'Aadhaar no' => [(string) ($app['aadhar_no'] ?? ''), (string) ($aadhar ?? '')],
            'Class' => [(string) $app['class_name'], $className],
            'Section' => [(string) ($app['target_section_id'] ?? ''), (string) $sectionId],
        ] as $field => [$oldValue, $newValue]) {
            if ((string) $oldValue !== (string) $newValue) {
                $changedFields[] = $field;
            }
        }
        $changedFields = array_values(array_unique($changedFields));
        $this->audit($pdo, $request, 'admissions', 'admission_form_updated', 'erp_admission_applications', (string) $id, [
            'status' => $details['admission_status'],
            'section_id' => $sectionId,
            'changedFields' => $changedFields,
            'changedCount' => count($changedFields),
        ]);

        return $this->json($response, ['data' => $this->findAdmission($pdo, $id)]);
    }

    public function admissionAudit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ((string) $request->getAttribute('user_role') !== 'admin') {
            return $this->json($response, ['error' => 'Only admin can view admission form audit logs'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->json($response, ['error' => 'Admission id is required'], 422);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            "SELECT l.id, l.user_id, l.module, l.action, l.entity_type, l.entity_id, l.metadata_json, l.created_at,
                    u.display_name, u.email
             FROM erp_audit_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.entity_type = 'erp_admission_applications'
               AND l.entity_id = ?
               AND l.module = 'admissions'
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT 50"
        );
        $st->execute([(string) $id]);
        $rows = array_map(static function (array $row): array {
            $meta = json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [];
            return [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'createdAt' => (string) $row['created_at'],
                'userId' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'userName' => (string) ($row['display_name'] ?: $row['email'] ?: 'System / website'),
                'userEmail' => (string) ($row['email'] ?? ''),
                'userRole' => (string) ($meta['userRole'] ?? ''),
                'status' => (string) ($meta['status'] ?? ''),
                'changedFields' => array_values(array_filter(array_map('strval', is_array($meta['changedFields'] ?? null) ? $meta['changedFields'] : []))),
                'changedCount' => (int) ($meta['changedCount'] ?? 0),
                'meta' => $meta,
            ];
        }, $st->fetchAll());

        return $this->json($response, ['data' => $rows]);
    }

    public function convertAdmission(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if ($role !== 'accountant') {
            return $this->json($response, ['error' => 'Only accountant can confirm paid admission conversion'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $sectionId = (int) ($body['sectionId'] ?? 0);
        $paymentAmount = (float) ($body['paymentAmount'] ?? 0);
        $paymentMethod = strtolower(str_replace(' ', '_', trim((string) ($body['paymentMethod'] ?? 'cash')) ?: 'cash'));
        $paymentReference = trim((string) ($body['paymentReference'] ?? '')) ?: null;
        $feeGroup = trim((string) ($body['feeGroup'] ?? ''));
        $ledgerFees = is_array($body['ledgerFees'] ?? null) ? array_values(array_filter(array_map(static function ($row): ?array {
            if (!is_array($row)) {
                return null;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $amount = (float) ($row['amount'] ?? 0);
            return $name !== '' && $amount > 0 ? ['name' => $name, 'amount' => $amount] : null;
        }, $body['ledgerFees']))) : [];
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
        if (in_array((string) $app['stage'], ['fee_paid', 'enrolled'], true)) {
            return $this->json($response, ['error' => 'Admission is already confirmed'], 409);
        }
        if ($sectionId <= 0) {
            $sec = $pdo->prepare('SELECT id FROM erp_sections WHERE class_id = ? ORDER BY name LIMIT 1');
            $sec->execute([(int) $app['target_class_id']]);
            $sectionId = (int) $sec->fetchColumn();
        }

        $details = json_decode((string) ($app['details_json'] ?? '{}'), true) ?: [];
        $status = strtolower(trim((string) ($details['admission_status'] ?? '')));
        if (!str_contains($status, 'document verified') && !str_contains($status, 'verified document')) {
            return $this->json($response, ['error' => 'Admission can be confirmed only after document verification'], 422);
        }
        $parts = preg_split('/\s+/', trim((string) $app['applicant_name']));
        $firstName = (string) ($parts[0] ?? $app['applicant_name']);
        $lastName = count($parts) > 1 ? (string) end($parts) : '';
        $admissionNo = 'ADM-' . date('Y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $aadhar = preg_replace('/\D+/', '', (string) ($app['aadhar_no'] ?? $details['Aadhar No'] ?? '')) ?: null;
        $gender = strtolower(trim((string) ($details['Gender'] ?? $details['gender'] ?? 'other')));
        if (!in_array($gender, ['female', 'male', 'other'], true)) {
            $gender = 'other';
        }
        $dateOfBirth = trim((string) ($details['Date of Birth'] ?? $details['date_of_birth'] ?? ''));
        if ($dateOfBirth === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
            $dateOfBirth = '2008-01-01';
        }

        $existingStudent = $pdo->prepare('SELECT id FROM erp_students WHERE application_id = ? OR admission_no = ? LIMIT 1');
        $existingStudent->execute([$id, $admissionNo]);
        $studentId = (int) ($existingStudent->fetchColumn() ?: 0);
        if ($studentId > 0) {
            $pdo->prepare(
                'UPDATE erp_students
                 SET class_id = ?, section_id = ?, application_id = ?, first_name = ?, last_name = ?, gender = ?, date_of_birth = ?, email = ?, phone = ?, status = ?
                 WHERE id = ?'
            )->execute([
                (int) $app['target_class_id'],
                $sectionId,
                $id,
                $firstName,
                $lastName,
                $gender,
                $dateOfBirth,
                $app['email'],
                $app['phone'],
                'active',
                $studentId,
            ]);
        } else {
            $student = $pdo->prepare(
                'INSERT INTO erp_students
                 (institution_id, academic_year_id, class_id, section_id, application_id, admission_no, roll_no, first_name, last_name, gender, date_of_birth, email, phone, status, admitted_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
            );
            $student->execute([
                (int) $app['institution_id'],
                (int) $app['academic_year_id'],
                (int) $app['target_class_id'],
                $sectionId,
                $id,
                $admissionNo,
                null,
                $firstName,
                $lastName,
                $gender,
                $dateOfBirth,
                $app['email'],
                $app['phone'],
                'active',
            ]);
            $studentId = (int) $pdo->lastInsertId();

            $guardian = $pdo->prepare('INSERT INTO erp_guardians (institution_id, name, relation, email, phone, address) VALUES (?, ?, ?, ?, ?, ?)');
            $guardian->execute([(int) $app['institution_id'], $app['guardian_name'], 'Guardian', $app['email'], $app['phone'], (string) ($details['residential_address'] ?? '')]);
            $guardianId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO erp_student_guardians (student_id, guardian_id, is_primary) VALUES (?, ?, 1)')->execute([$studentId, $guardianId]);
        }

        $this->ensureFinanceSchema($pdo);
        $plans = $pdo->prepare('SELECT id, name, amount, due_on FROM erp_fee_plans WHERE class_id = ? ORDER BY due_on, id');
        $plans->execute([(int) $app['target_class_id']]);
        $fees = $plans->fetchAll();
        $admissionInvoiceId = null;
        $admissionFeeAmount = 0.0;
        if ($ledgerFees !== []) {
            foreach ($ledgerFees as $fee) {
                $invoice = $this->createFeeInvoiceForStudent($pdo, $studentId, (string) $fee['name'], (float) $fee['amount']);
                $invoiceId = (int) $invoice['id'];
                $isAdmissionFee = str_contains(strtolower((string) $fee['name']), 'admission');
                if ($admissionInvoiceId === null || $isAdmissionFee) {
                    $admissionInvoiceId = $invoiceId;
                    $admissionFeeAmount = (float) $fee['amount'];
                }
            }
        } elseif ($fees) {
            foreach ($fees as $fee) {
                $invoiceId = $this->createInvoiceForFeePlan($pdo, $studentId, (int) $fee['id'], (float) $fee['amount'], (string) $fee['due_on']);
                $isAdmissionFee = str_contains(strtolower((string) $fee['name']), 'admission');
                if ($admissionInvoiceId === null || $isAdmissionFee) {
                    $admissionInvoiceId = $invoiceId;
                    $admissionFeeAmount = (float) $fee['amount'];
                }
            }
        }
        if ($ledgerFees !== [] || $fees) {
            if ($admissionInvoiceId === null) {
                return $this->json($response, ['error' => 'Admission fee ledger could not be prepared'], 422);
            }
            $posted = min($paymentAmount, $admissionFeeAmount > 0 ? $admissionFeeAmount : $paymentAmount);
            $receiptNo = 'RCT-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_payments') + 1), 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO erp_fee_payments (invoice_id, receipt_no, fee_head, amount, method, paid_at, reference_no) VALUES (?, ?, ?, ?, ?, NOW(), ?)')
                ->execute([$admissionInvoiceId, $receiptNo, 'Admission Fees', $posted, $paymentMethod, $paymentReference]);
            $invoiceStatus = $posted >= $admissionFeeAmount ? 'paid' : 'partial';
            $pdo->prepare('UPDATE erp_fee_invoices SET paid_amount = ?, status = ? WHERE id = ?')->execute([$posted, $invoiceStatus, $admissionInvoiceId]);
        } else {
            $invoice = $this->createFeeInvoiceForStudent($pdo, $studentId, 'Admission fee', $paymentAmount);
            $receiptNo = 'RCT-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_payments') + 1), 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO erp_fee_payments (invoice_id, receipt_no, fee_head, amount, method, paid_at, reference_no) VALUES (?, ?, ?, ?, ?, NOW(), ?)')
                ->execute([(int) $invoice['id'], $receiptNo, 'Admission fee', $paymentAmount, $paymentMethod, $paymentReference]);
            $pdo->prepare('UPDATE erp_fee_invoices SET paid_amount = ?, status = ? WHERE id = ?')->execute([$paymentAmount, 'paid', (int) $invoice['id']]);
        }

        $studentAccount = null;
        if ($aadhar !== null && strlen($aadhar) === 12) {
            $account = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $account->execute([$aadhar]);
            $accountId = (int) ($account->fetchColumn() ?: 0);
            if ($accountId <= 0) {
                $pdo->prepare('INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, ?)')
                    ->execute([$aadhar, trim($firstName . ' ' . $lastName), password_hash('Pass@123', PASSWORD_DEFAULT), 'student']);
                $accountId = (int) $pdo->lastInsertId();
            }
            $studentAccount = ['id' => $accountId, 'userId' => $aadhar, 'defaultPassword' => 'Pass@123'];
        }

        $details['admission_status'] = 'Active admission';
        if ($feeGroup !== '') {
            $details['fee_group'] = $feeGroup;
        }
        $details['admission_fee_paid'] = $paymentAmount;
        $details['admission_fee_receipt_no'] = $receiptNo ?? null;
        $details['admission_fee_paid_at'] = date('Y-m-d H:i:s');
        $details['student_account_user_id'] = $studentAccount['userId'] ?? null;
        $pdo->prepare('UPDATE erp_admission_applications SET stage = ?, target_section_id = ?, details_json = ? WHERE id = ?')->execute(['enrolled', $sectionId, json_encode($details), $id]);
        $this->audit($pdo, $request, 'admissions', 'converted', 'erp_admission_applications', (string) $id, ['student_id' => $studentId, 'section_id' => $sectionId, 'payment_amount' => $paymentAmount, 'student_account' => $studentAccount['userId'] ?? null]);

        return $this->json($response, ['data' => $this->findAdmission($pdo, $id), 'studentId' => $studentId, 'receiptNo' => $receiptNo ?? null, 'studentAccount' => $studentAccount], 201);
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

    public function importExistingStudents(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!in_array((string) $request->getAttribute('user_role'), ['admin', 'clerk'], true)) {
            return $this->json($response, ['error' => 'Only admin or clerk can bulk import existing students'], 403);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
        $createAccounts = filter_var($body['createPortalAccounts'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $applyClassFees = filter_var($body['applyClassFees'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($rows === []) {
            return $this->json($response, ['error' => 'No student rows received for import'], 422);
        }
        if (count($rows) > 1000) {
            return $this->json($response, ['error' => 'Import maximum is 1000 students per upload'], 422);
        }

        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $academicYearId = $this->activeAcademicYearId($pdo, $institutionId);
        $this->ensureFinanceSchema($pdo);
        $digitsOnly = static fn (string $value): string => preg_replace('/\D+/', '', $value) ?? '';

        $imported = [];
        $skipped = [];
        $errors = [];
        $createdAccounts = 0;
        $createdInvoices = 0;

        foreach ($rows as $index => $rawRow) {
            if (!is_array($rawRow)) {
                $errors[] = ['row' => $index + 2, 'message' => 'Row is not a valid object'];
                continue;
            }
            $row = $this->normalizeImportRow($rawRow);
            $excelRow = (int) ($row['row_number'] ?? ($index + 2));
            try {
                $firstName = strtoupper(trim($this->importValue($row, ['first_name', 'student_first_name', 'first name'])));
                $middleName = strtoupper(trim($this->importValue($row, ['middle_father_name', 'middle_name', 'father_name', 'middle / father name'])));
                $lastName = strtoupper(trim($this->importValue($row, ['last_name', 'surname', 'last name / surname'])));
                $fullName = strtoupper(trim($this->importValue($row, ['full_name_as_on_aadhar', 'full_name', 'student_name', 'name'])));
                if ($firstName === '' && $fullName !== '') {
                    $parts = preg_split('/\s+/', $fullName);
                    $firstName = (string) ($parts[0] ?? '');
                    $lastName = count($parts) > 1 ? (string) end($parts) : $lastName;
                    $middleName = count($parts) > 2 ? trim(implode(' ', array_slice($parts, 1, -1))) : $middleName;
                }
                $applicantName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName]))) ?: $fullName;
                $admissionNo = strtoupper(trim($this->importValue($row, ['admission_no', 'admission number', 'admission no'])));
                $aadhar = $digitsOnly($this->importValue($row, ['aadhar_no', 'aadhaar_no', 'aadhaar number', 'aadhar number']));
                $phone = $digitsOnly($this->importValue($row, ['mobile_no', 'mobile number', 'phone']));
                $email = strtolower(trim($this->importValue($row, ['email_id', 'email'])));
                $gender = trim($this->importValue($row, ['gender'])) ?: 'other';
                $dob = trim($this->importValue($row, ['date_of_birth', 'dob', 'date of birth']));

                if ($applicantName === '' || $firstName === '') {
                    throw new \RuntimeException('Student first name/full name is required');
                }
                if ($admissionNo === '' && $aadhar === '') {
                    throw new \RuntimeException('Admission No or Aadhaar No is required');
                }
                if ($aadhar !== '' && strlen($aadhar) !== 12) {
                    throw new \RuntimeException('Aadhaar number must be 12 digits');
                }
                if ($phone !== '' && strlen($phone) !== 10) {
                    throw new \RuntimeException('Mobile number must be 10 digits');
                }
                if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                    throw new \RuntimeException('Date of birth must be YYYY-MM-DD');
                }

                $classIdInput = trim($this->importValue($row, ['class_id']));
                $sectionIdInput = trim($this->importValue($row, ['section_id']));
                $classId = ctype_digit($classIdInput) ? (int) $classIdInput : $this->resolveClassIdStrict($pdo, $institutionId, trim($this->importValue($row, ['class_name', 'admission_class', 'class'])));
                if ($classId <= 0) {
                    throw new \RuntimeException('Class not found. Add the class in Master Entries first.');
                }
                $sectionId = ctype_digit($sectionIdInput) ? (int) $sectionIdInput : $this->resolveSectionIdStrict($pdo, $classId, trim($this->importValue($row, ['section_name', 'section'])));
                if ($sectionId <= 0) {
                    throw new \RuntimeException('Section not found. Add the section in Master Entries first.');
                }

                if ($admissionNo !== '') {
                    $exists = $pdo->prepare('SELECT id FROM erp_students WHERE institution_id = ? AND admission_no = ? LIMIT 1');
                    $exists->execute([$institutionId, $admissionNo]);
                    if ((int) $exists->fetchColumn() > 0) {
                        $skipped[] = ['row' => $excelRow, 'message' => 'Admission No already exists', 'admissionNo' => $admissionNo];
                        continue;
                    }
                }
                if ($aadhar !== '') {
                    $exists = $pdo->prepare(
                        'SELECT id FROM erp_admission_applications
                         WHERE institution_id = ?
                           AND REPLACE(REPLACE(REPLACE(COALESCE(aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ?
                         LIMIT 1'
                    );
                    $exists->execute([$institutionId, $aadhar]);
                    if ((int) $exists->fetchColumn() > 0) {
                        $skipped[] = ['row' => $excelRow, 'message' => 'Aadhaar already exists in admission records', 'aadharNo' => $aadhar];
                        continue;
                    }
                }

                $classLabel = $this->classNameById($pdo, $classId);
                $sectionLabel = $this->sectionNameById($pdo, $sectionId);
                if ($admissionNo === '') {
                    $admissionNo = 'EX-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_students') + count($imported) + 1), 5, '0', STR_PAD_LEFT);
                }

                $details = $this->detailsFromExistingStudentImportRow($row);
                $details['Admission Class'] = $classLabel;
                $details['Class Section Id'] = (string) $sectionId;
                $details['Section'] = $sectionLabel;
                $details['Application Sr. No'] = $admissionNo;
                $details['Index No'] = $admissionNo;
                $details['Aadhar No'] = $aadhar;
                $details['First Name'] = $firstName;
                $details['Middle / Father Name'] = $middleName;
                $details['Last Name / Surname'] = $lastName;
                $details['Full Name as on Aadhar'] = $fullName !== '' ? $fullName : $applicantName;
                $details['Mobile No'] = $phone;
                $details['Email Id'] = $email;
                $details['Date of Birth'] = $dob;
                $details['admission_status'] = 'Active admission - existing student import';
                $details['existing_student_import'] = true;

                $guardianName = strtoupper(trim($this->importValue($row, ['guardian_name', 'parent_name', 'father_name', 'middle_father_name']))) ?: ($middleName ?: 'GUARDIAN');
                $guardianPhone = $digitsOnly($this->importValue($row, ['guardian_mobile', 'parent_mobile', "parent's/guardian's mobile number"]));
                $guardianEmail = strtolower(trim($this->importValue($row, ['guardian_email', 'parent_email'])));
                $address = trim($this->importValue($row, ['residential_address', 'address']));

                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO erp_students
                     (institution_id, academic_year_id, class_id, section_id, admission_no, roll_no, first_name, last_name, gender, date_of_birth, email, phone, status, admitted_on)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $institutionId,
                    $academicYearId,
                    $classId,
                    $sectionId,
                    $admissionNo,
                    trim($this->importValue($row, ['roll_no', 'roll number'])) ?: null,
                    $firstName,
                    $lastName,
                    $gender,
                    $dob !== '' ? $dob : null,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    'active',
                    trim($this->importValue($row, ['admission_date', 'admitted_on'])) ?: date('Y-m-d'),
                ]);
                $studentId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO erp_admission_applications
                     (institution_id, academic_year_id, target_class_id, target_section_id, applicant_name, guardian_name, phone, email, aadhar_no, details_json, stage, score, source)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $institutionId,
                    $academicYearId,
                    $classId,
                    $sectionId,
                    $applicantName,
                    $guardianName,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $aadhar !== '' ? $aadhar : null,
                    json_encode($details, JSON_THROW_ON_ERROR),
                    'enrolled',
                    0,
                    'Existing student import',
                ]);
                $applicationId = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO erp_guardians (institution_id, name, relation, email, phone, address) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$institutionId, $guardianName, 'Guardian', $guardianEmail ?: null, $guardianPhone ?: null, $address ?: null]);
                $guardianId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO erp_student_guardians (student_id, guardian_id, is_primary) VALUES (?, ?, 1)')->execute([$studentId, $guardianId]);

                $accountUserId = null;
                if ($createAccounts && $aadhar !== '') {
                    $account = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                    $account->execute([$aadhar]);
                    $accountId = (int) ($account->fetchColumn() ?: 0);
                    if ($accountId <= 0) {
                        $pdo->prepare('INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, ?)')
                            ->execute([$aadhar, $applicantName, password_hash('Pass@123', PASSWORD_DEFAULT), 'student']);
                        $createdAccounts++;
                    }
                    $accountUserId = $aadhar;
                }

                if ($applyClassFees) {
                    $plans = $pdo->prepare('SELECT id, amount, due_on FROM erp_fee_plans WHERE class_id = ? ORDER BY due_on, id');
                    $plans->execute([$classId]);
                    foreach ($plans->fetchAll() as $fee) {
                        $this->createInvoiceForFeePlan($pdo, $studentId, (int) $fee['id'], (float) $fee['amount'], (string) $fee['due_on']);
                        $createdInvoices++;
                    }
                }

                $this->audit($pdo, $request, 'students', 'existing_student_imported', 'erp_students', (string) $studentId, [
                    'applicationId' => $applicationId,
                    'admissionNo' => $admissionNo,
                    'classId' => $classId,
                    'sectionId' => $sectionId,
                    'studentAccount' => $accountUserId,
                ]);
                $pdo->commit();

                $imported[] = [
                    'row' => $excelRow,
                    'studentId' => $studentId,
                    'applicationId' => $applicationId,
                    'admissionNo' => $admissionNo,
                    'name' => $applicantName,
                    'className' => $classLabel,
                    'sectionName' => $sectionLabel,
                    'studentLogin' => $accountUserId,
                ];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = ['row' => $excelRow, 'message' => $e->getMessage()];
            }
        }

        return $this->json($response, [
            'data' => [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'summary' => [
                    'received' => count($rows),
                    'imported' => count($imported),
                    'skipped' => count($skipped),
                    'errors' => count($errors),
                    'studentAccountsCreated' => $createdAccounts,
                    'feeInvoicesCreated' => $createdInvoices,
                    'defaultPassword' => $createAccounts ? 'Pass@123' : null,
                ],
            ],
        ], $errors === [] ? 201 : 207);
    }

    public function promoteExistingStudent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!in_array((string) $request->getAttribute('user_role'), ['admin', 'clerk'], true)) {
            return $this->json($response, ['error' => 'Only admin or clerk can promote older students'], 403);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $studentId = (int) ($body['studentId'] ?? 0);
        $toClassId = (int) ($body['toClassId'] ?? 0);
        $toSectionId = (int) ($body['toSectionId'] ?? 0);
        $effectiveDate = trim((string) ($body['effectiveDate'] ?? date('Y-m-d')));
        $note = trim((string) ($body['note'] ?? ''));

        if ($studentId <= 0 || $toClassId <= 0 || $toSectionId <= 0) {
            return $this->json($response, ['error' => 'Student, target class and target section are required'], 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return $this->json($response, ['error' => 'Promotion date must be YYYY-MM-DD'], 422);
        }

        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $this->ensureStudentPromotionSchema($pdo);

        $student = $pdo->prepare(
            'SELECT id, class_id, section_id, admission_no, first_name, last_name
             FROM erp_students
             WHERE id = ? AND institution_id = ?
             LIMIT 1'
        );
        $student->execute([$studentId, $institutionId]);
        $row = $student->fetch();
        if (!$row) {
            return $this->json($response, ['error' => 'Student not found'], 404);
        }

        $sectionCheck = $pdo->prepare('SELECT id FROM erp_sections WHERE id = ? AND class_id = ? LIMIT 1');
        $sectionCheck->execute([$toSectionId, $toClassId]);
        if (!$sectionCheck->fetchColumn()) {
            return $this->json($response, ['error' => 'Selected section does not belong to the target class'], 422);
        }

        $fromClassId = (int) ($row['class_id'] ?? 0);
        $fromSectionId = (int) ($row['section_id'] ?? 0);
        if ($fromClassId === $toClassId && $fromSectionId === $toSectionId) {
            return $this->json($response, ['error' => 'Student is already in the selected class and section'], 422);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO erp_student_promotions
                 (institution_id, student_id, from_class_id, from_section_id, to_class_id, to_section_id, effective_date, note, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $institutionId,
                $studentId,
                $fromClassId ?: null,
                $fromSectionId ?: null,
                $toClassId,
                $toSectionId,
                $effectiveDate,
                $note !== '' ? $note : null,
                (int) ($request->getAttribute('user_id') ?? 0) ?: null,
            ]);
            $promotionId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE erp_students SET class_id = ?, section_id = ? WHERE id = ? AND institution_id = ?')
                ->execute([$toClassId, $toSectionId, $studentId, $institutionId]);

            $admissionNo = (string) ($row['admission_no'] ?? '');
            if ($admissionNo !== '') {
                $application = $pdo->prepare(
                    'SELECT id, details_json
                     FROM erp_admission_applications
                     WHERE institution_id = ?
                       AND (JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Index No\"")) = ?
                            OR JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Application Sr. No\"")) = ?)
                     ORDER BY id DESC
                     LIMIT 1'
                );
                $application->execute([$institutionId, $admissionNo, $admissionNo]);
                $applicationRow = $application->fetch();
                if ($applicationRow) {
                    $details = json_decode((string) ($applicationRow['details_json'] ?? '{}'), true);
                    if (!is_array($details)) {
                        $details = [];
                    }
                    $history = is_array($details['class_history'] ?? null) ? $details['class_history'] : [];
                    $history[] = [
                        'fromClass' => $this->classNameById($pdo, $fromClassId),
                        'fromSection' => $this->sectionNameById($pdo, $fromSectionId),
                        'toClass' => $this->classNameById($pdo, $toClassId),
                        'toSection' => $this->sectionNameById($pdo, $toSectionId),
                        'date' => $effectiveDate,
                        'note' => $note,
                    ];
                    $details['Admission Class'] = $this->classNameById($pdo, $toClassId);
                    $details['Class Section Id'] = (string) $toSectionId;
                    $details['Section'] = $this->sectionNameById($pdo, $toSectionId);
                    $details['class_history'] = $history;
                    $pdo->prepare('UPDATE erp_admission_applications SET target_class_id = ?, target_section_id = ?, details_json = ? WHERE id = ?')
                        ->execute([$toClassId, $toSectionId, json_encode($details, JSON_THROW_ON_ERROR), (int) $applicationRow['id']]);
                }
            }

            $this->audit($pdo, $request, 'students', 'existing_student_promoted', 'erp_student_promotions', (string) $promotionId, [
                'studentId' => $studentId,
                'fromClassId' => $fromClassId,
                'toClassId' => $toClassId,
                'fromSectionId' => $fromSectionId,
                'toSectionId' => $toSectionId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->json($response, ['data' => [
            'studentId' => $studentId,
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'fromClass' => $this->classNameById($pdo, $fromClassId),
            'fromSection' => $this->sectionNameById($pdo, $fromSectionId),
            'toClass' => $this->classNameById($pdo, $toClassId),
            'toSection' => $this->sectionNameById($pdo, $toSectionId),
            'effectiveDate' => $effectiveDate,
        ]]);
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

    public function updateUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actorRole = (string) $request->getAttribute('user_role');
        if ($actorRole !== 'admin') {
            return $this->json($response, ['error' => 'Only super admin can edit portal users'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->json($response, ['error' => 'Valid user id is required'], 422);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $role = trim((string) ($body['role'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['error' => 'Valid name and email are required'], 422);
        }
        if ($password !== '' && strlen($password) < 8) {
            return $this->json($response, ['error' => 'Password must be at least 8 characters'], 422);
        }

        $pdo = $this->db->pdo();
        $storedRole = $role !== '' ? $this->roleSlug($role) : 'teacher';
        $fields = ['email = ?', 'display_name = ?', 'role = ?'];
        $values = [$email, $name, $storedRole];
        if ($password !== '') {
            $fields[] = 'password_hash = ?';
            $fields[] = 'invite_token_hash = NULL';
            $fields[] = 'invite_expires_at = NULL';
            $fields[] = 'invite_accepted_at = NOW()';
            $values[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $values[] = $id;
        try {
            $st = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $st->execute($values);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->json($response, ['error' => 'A user with this email already exists'], 409);
            }
            throw $e;
        }
        if ($st->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) {
                return $this->json($response, ['error' => 'User not found'], 404);
            }
        }
        $this->audit($pdo, $request, 'users', $password !== '' ? 'password_reset' : 'updated', 'users', (string) $id, ['role' => $role, 'email' => $email]);
        $user = $pdo->prepare('SELECT id, email, display_name, role, created_at FROM users WHERE id = ? LIMIT 1');
        $user->execute([$id]);
        $row = $user->fetch();
        return $this->json($response, [
            'data' => [
                'id' => 'USR-' . (int) $row['id'],
                'name' => $row['display_name'] ?: $row['email'],
                'email' => $row['email'],
                'role' => $row['role'] === 'admin' ? 'Super Admin' : ucfirst((string) $row['role']),
                'persona' => $row['role'] === 'admin' ? 'Admin' : ucfirst((string) $row['role']),
                'status' => $row['role'] === 'invite_pending' ? 'Invite pending' : 'Active',
                'lastLogin' => $password !== '' ? 'Password reset by super admin' : 'Updated by super admin',
                'mfa' => $row['role'] === 'admin',
            ],
        ]);
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
        $institution = $pdo->query('SELECT id, name, code, type, email, phone, address FROM erp_institutions ORDER BY id LIMIT 1')->fetch();
        $academicYears = $pdo->query('SELECT id, name, starts_on, ends_on, is_active FROM erp_academic_years ORDER BY starts_on DESC, id DESC')->fetchAll();
        $this->ensureAdmissionOtpSchema($pdo);
        $communicationSettings = $this->emailSettingsResponse($this->loadEmailSettings($pdo) ?: []);
        $classes = $pdo->query('SELECT id, name, level_order FROM erp_classes ORDER BY level_order, id')->fetchAll();
        $courseRows = $pdo->query("SELECT id, name, payload_json FROM erp_saved_records WHERE module = 'Course master' ORDER BY created_at DESC")->fetchAll();
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
                'shortName' => (string) ($payload['shortName'] ?? $payload['short_name'] ?? strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $course), 0, 6))),
                'notes' => (string) ($payload['notes'] ?? ''),
            ];
        }
        $courses = array_values($courseMap);
        $faculties = [];
        $classMappingRows = $pdo->query("SELECT payload_json FROM erp_saved_records WHERE module = 'Class course mapping' ORDER BY created_at DESC")->fetchAll();
        $classMappings = [];
        foreach ($classMappingRows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            $classId = (int) ($payload['classId'] ?? 0);
            if ($classId > 0 && !isset($classMappings[$classId])) {
                $classMappings[$classId] = [
                    'course' => (string) ($payload['course'] ?? ''),
                ];
            }
        }
        foreach ($classes as &$class) {
            $mapping = $classMappings[(int) $class['id']] ?? [];
            $class['course'] = (string) ($mapping['course'] ?? '');
        }
        unset($class);
        $sections = $pdo->query('SELECT s.id, s.class_id, c.name AS class_name, s.name, s.capacity FROM erp_sections s JOIN erp_classes c ON c.id = s.class_id ORDER BY c.level_order, s.name')->fetchAll();
        $this->ensureCourseSubjectPlanSchema($pdo);
        $this->ensureSubjectSelectionLimitSchema($pdo);
        $this->ensureDynamicSubjectPlanSchema($pdo);
        $subjects = $pdo->query('SELECT id, code, name, subject_type FROM erp_subjects ORDER BY name')->fetchAll();
        $courseSubjectPlans = $pdo->query(
            'SELECT p.course_name, p.row_no, p.semester_no, p.subject_id, sub.code, sub.name, sub.subject_type
             FROM erp_course_subject_plans p
             JOIN erp_subjects sub ON sub.id = p.subject_id
             ORDER BY p.course_name, p.row_no, p.semester_no'
        )->fetchAll();
        $sectionSubjects = $pdo->query('SELECT ss.section_id, ss.subject_id, ss.semester_no, ss.is_mandatory, sub.code, sub.name, sub.subject_type FROM erp_section_subjects ss JOIN erp_subjects sub ON sub.id = ss.subject_id ORDER BY ss.section_id, ss.semester_no, sub.name')->fetchAll();
        $subjectLimits = $pdo->query('SELECT section_id, semester_no, max_subjects FROM erp_section_subject_limits ORDER BY section_id, semester_no')->fetchAll();
        $subjectGroups = $pdo->query('SELECT g.id, g.course_name, g.group_name, g.description, GROUP_CONCAT(sub.name ORDER BY sub.name SEPARATOR ", ") AS subjects FROM erp_subject_groups g LEFT JOIN erp_subject_group_subjects gs ON gs.group_id = g.id LEFT JOIN erp_subjects sub ON sub.id = gs.subject_id GROUP BY g.id, g.course_name, g.group_name, g.description ORDER BY g.course_name, g.group_name')->fetchAll();
        $courseYearGroups = $this->normalizeCourseYearGroups($pdo->query('SELECT * FROM erp_course_year_subject_groups ORDER BY course_name, year_name, sort_order, id')->fetchAll());
        $courseYearGroupSubjects = $pdo->query(
            'SELECT gs.group_id, g.course_name, g.year_name, g.group_key, gs.semester_no, gs.subject_id, gs.subject_family_key, gs.sort_order, gs.is_default, sub.code, sub.name, sub.subject_type
             FROM erp_course_year_group_subjects gs
             JOIN erp_course_year_subject_groups g ON g.id = gs.group_id
             JOIN erp_subjects sub ON sub.id = gs.subject_id
             ORDER BY g.course_name, g.year_name, g.group_key, gs.semester_no, gs.sort_order, sub.name'
        )->fetchAll();
        $subjectPapers = $pdo->query(
            'SELECT p.id, p.course_name, p.year_name, p.semester_no, p.subject_id, sub.code AS subject_code, sub.name AS subject_name, p.paper_code, p.paper_name, p.paper_type, p.sort_order
             FROM erp_subject_papers p
             JOIN erp_subjects sub ON sub.id = p.subject_id
             ORDER BY p.course_name, p.year_name, p.semester_no, sub.name, p.sort_order, p.paper_code'
        )->fetchAll();
        $staff = $pdo->query("SELECT id, employee_no, CONCAT(first_name, ' ', last_name) AS name, role FROM erp_staff WHERE status = 'active' ORDER BY first_name")->fetchAll();
        $feePlans = $pdo->query('SELECT f.id, f.name, f.amount, f.due_on, c.name AS class_name FROM erp_fee_plans f LEFT JOIN erp_classes c ON c.id = f.class_id ORDER BY f.due_on, f.id')->fetchAll();
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
        $students = $pdo->query("
            SELECT
                s.id,
                CONCAT(s.first_name, ' ', s.last_name) AS name,
                s.first_name,
                s.last_name,
                s.admission_no,
                s.date_of_birth,
                s.phone,
                s.email,
                c.name AS class_name,
                sec.name AS section_name,
                sec.id AS section_id,
                app.id AS application_numeric_id,
                CASE
                    WHEN app.id IS NOT NULL THEN CONCAT('RSL-', YEAR(COALESCE(app.created_at, NOW())), '-', LPAD(app.id, 4, '0'))
                    ELSE NULL
                END AS application_ref
            FROM erp_students s
            JOIN erp_classes c ON c.id = s.class_id
            JOIN erp_sections sec ON sec.id = s.section_id
            LEFT JOIN erp_admission_applications app ON app.id = (
                SELECT a.id
                FROM erp_admission_applications a
                WHERE a.stage = 'enrolled'
                  AND a.target_class_id = s.class_id
                  AND (
                    (s.phone IS NOT NULL AND s.phone <> '' AND a.phone = s.phone)
                    OR (s.email IS NOT NULL AND s.email <> '' AND a.email = s.email)
                    OR JSON_UNQUOTE(JSON_EXTRACT(a.details_json, '$.\"Aadhar No\"')) = s.admission_no
                  )
                ORDER BY a.id DESC
                LIMIT 1
            )
            ORDER BY s.first_name
        ")->fetchAll();

        return $this->json($response, [
            'data' => [
                'institution' => [
                    'id' => $institutionId,
                    'name' => (string) ($institution['name'] ?? ''),
                    'code' => (string) ($institution['code'] ?? ''),
                    'type' => (string) ($institution['type'] ?? ''),
                    'email' => (string) ($institution['email'] ?? ''),
                    'phone' => (string) ($institution['phone'] ?? ''),
                    'address' => (string) ($institution['address'] ?? ''),
                ],
                'academicYears' => $academicYears,
                'communicationSettings' => $communicationSettings,
                'courses' => $courses,
                'faculties' => $faculties,
                'classes' => $classes,
                'sections' => $sections,
                'subjects' => $subjects,
                'courseSubjectPlans' => $courseSubjectPlans,
                'sectionSubjects' => $sectionSubjects,
                'subjectLimits' => $subjectLimits,
                'subjectGroups' => $subjectGroups,
                'courseYearGroups' => $courseYearGroups,
                'courseYearGroupSubjects' => $courseYearGroupSubjects,
                'subjectPapers' => $subjectPapers,
                'staff' => $staff,
                'feePlans' => $feePlans,
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
        $id = (int) ($body['id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        $levelOrder = (int) ($body['levelOrder'] ?? 0);
        $course = trim((string) ($body['course'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'Class name is required'], 422);
        }
        if ($course === '') {
            return $this->json($response, ['error' => 'Course is required for class registration'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        if ($id > 0) {
            $existing = $pdo->prepare('SELECT id FROM erp_classes WHERE id = ? AND institution_id = ?');
            $existing->execute([$id, $institutionId]);
            if (!$existing->fetchColumn()) {
                return $this->json($response, ['error' => 'Class not found'], 404);
            }
            $st = $pdo->prepare('UPDATE erp_classes SET name = ?, level_order = ? WHERE id = ? AND institution_id = ?');
            $st->execute([$name, $levelOrder, $id, $institutionId]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO erp_classes (institution_id, name, level_order)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE level_order = VALUES(level_order)'
            );
            $st->execute([$institutionId, $name, $levelOrder]);
            $id = (int) $pdo->lastInsertId();
        }
        if ($id === 0) {
            $lookup = $pdo->prepare('SELECT id FROM erp_classes WHERE institution_id = ? AND name = ?');
            $lookup->execute([$institutionId, $name]);
            $id = (int) $lookup->fetchColumn();
        }
        $payload = ['classId' => $id, 'name' => $name, 'levelOrder' => $levelOrder, 'course' => $course] + $this->activeAcademicYearMeta($pdo, $institutionId);
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
        return $this->json($response, ['data' => ['id' => $id, 'name' => $name, 'level_order' => $levelOrder, 'course' => $course]], 201);
    }

    public function deleteMasterClass(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can delete classes'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->json($response, ['error' => 'Class id is required'], 422);
        }
        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM erp_sections WHERE class_id = ?');
        $countStatement->execute([$id]);
        if ((int) $countStatement->fetchColumn() > 0) {
            return $this->json($response, ['error' => 'Delete sections of this class first'], 409);
        }
        $studentStatement = $pdo->prepare('SELECT COUNT(*) FROM erp_students WHERE class_id = ?');
        $studentStatement->execute([$id]);
        if ((int) $studentStatement->fetchColumn() > 0) {
            return $this->json($response, ['error' => 'Class has students and cannot be deleted'], 409);
        }
        $delete = $pdo->prepare('DELETE FROM erp_classes WHERE id = ? AND institution_id = ?');
        $delete->execute([$id, $institutionId]);
        $cleanup = $pdo->prepare("DELETE FROM erp_saved_records WHERE module = 'Class course mapping' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.classId')) = ?");
        $cleanup->execute([(string) $id]);
        $this->audit($pdo, $request, 'masters', 'class_deleted', 'erp_class', (string) $id, ['id' => $id]);
        return $this->json($response, ['data' => ['deleted' => true, 'id' => $id]]);
    }

    public function saveMasterCourse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can manage courses'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $id = trim((string) ($body['id'] ?? ''));
        $course = trim((string) ($body['course'] ?? ''));
        $shortName = strtoupper(trim((string) ($body['shortName'] ?? $body['short_name'] ?? '')));
        $shortName = preg_replace('/[^A-Z0-9]+/', '', $shortName) ?: strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $course), 0, 6));
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
            if (($id !== '' && (string) $row['id'] === $id) || strcasecmp(trim((string) ($payload['course'] ?? '')), $course) === 0) {
                $payload['shortName'] = $shortName;
                $payload['notes'] = $notes !== '' ? $notes : (string) ($payload['notes'] ?? '');
                $payload['course'] = $course;
                $payload += $yearMeta;
                $update = $pdo->prepare('UPDATE erp_saved_records SET payload_json = ?, name = ?, status = ? WHERE id = ?');
                $update->execute([json_encode($payload, JSON_THROW_ON_ERROR), $course, 'Active', (string) $row['id']]);
                return $this->json($response, ['data' => ['id' => (string) $row['id'], 'course' => $course, 'shortName' => $shortName, 'notes' => (string) ($payload['notes'] ?? '')]], 200);
            }
        }
        $id = 'COURSE-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $payload = ['course' => $course, 'shortName' => $shortName, 'notes' => $notes] + $yearMeta;
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
        return $this->json($response, ['data' => ['id' => $id, 'course' => $course, 'shortName' => $shortName, 'notes' => $notes]], 201);
    }

    public function deleteMasterCourse(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can delete courses'], 403);
        }
        $id = trim((string) ($args['id'] ?? ''));
        if ($id === '') {
            return $this->json($response, ['error' => 'Course id is required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $row = $pdo->prepare("SELECT payload_json FROM erp_saved_records WHERE id = ? AND module = 'Course master'");
        $row->execute([$id]);
        $payload = json_decode((string) ($row->fetchColumn() ?: '{}'), true) ?: [];
        $course = trim((string) ($payload['course'] ?? ''));
        if ($course !== '') {
            $classMap = $pdo->prepare("SELECT COUNT(*) FROM erp_saved_records WHERE module = 'Class course mapping' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.course')) = ?");
            $classMap->execute([$course]);
            if ((int) $classMap->fetchColumn() > 0) {
                return $this->json($response, ['error' => 'Course is mapped to classes. Move or delete those classes first.'], 409);
            }
        }
        $delete = $pdo->prepare("DELETE FROM erp_saved_records WHERE id = ? AND module = 'Course master'");
        $delete->execute([$id]);
        $this->audit($pdo, $request, 'masters', 'course_deleted', 'erp_saved_records', $id, ['id' => $id, 'course' => $course]);
        return $this->json($response, ['data' => ['deleted' => true, 'id' => $id]]);
    }

    public function saveAcademicYear(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can manage academic years'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $id = (int) ($body['id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        $startsOn = trim((string) ($body['startsOn'] ?? $body['starts_on'] ?? ''));
        $endsOn = trim((string) ($body['endsOn'] ?? $body['ends_on'] ?? ''));
        $isActive = filter_var($body['isActive'] ?? $body['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn)) {
            return $this->json($response, ['error' => 'Academic year name, start date and end date are required'], 422);
        }
        if ($startsOn > $endsOn) {
            return $this->json($response, ['error' => 'Start date cannot be after end date'], 422);
        }
        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        if ($isActive) {
            $deactivate = $pdo->prepare('UPDATE erp_academic_years SET is_active = 0 WHERE institution_id = ?');
            $deactivate->execute([$institutionId]);
        }
        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE erp_academic_years
                 SET name = ?, starts_on = ?, ends_on = ?, is_active = ?
                 WHERE id = ? AND institution_id = ?'
            );
            $st->execute([$name, $startsOn, $endsOn, $isActive ? 1 : 0, $id, $institutionId]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO erp_academic_years (institution_id, name, starts_on, ends_on, is_active)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$institutionId, $name, $startsOn, $endsOn, $isActive ? 1 : 0]);
            $id = (int) $pdo->lastInsertId();
        }
        $payload = ['id' => $id, 'name' => $name, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'is_active' => $isActive ? 1 : 0];
        $this->audit($pdo, $request, 'masters', 'academic_year_saved', 'erp_academic_years', (string) $id, $payload);
        return $this->json($response, ['data' => $payload], 201);
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
        $category = trim((string) ($body['category'] ?? ''));
        $type = $category !== '' ? $category : 'Subject';
        $sectionId = (int) ($body['sectionId'] ?? 0);
        $semester = max(1, min(2, (int) ($body['semester'] ?? 1)));
        $mandatory = ((string) ($body['mandatory'] ?? 'Yes')) === 'Yes' ? 1 : 0;
        if ($code === '' || $name === '') {
            return $this->json($response, ['error' => 'Subject code and subject name are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSubjectTypeSupportsGroups($pdo);
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

    private function ensureSubjectTypeSupportsGroups(PDO $pdo): void
    {
        try {
            $column = $pdo->query("SHOW COLUMNS FROM erp_subjects LIKE 'subject_type'")->fetch();
            $type = strtolower((string) ($column['Type'] ?? ''));
            if (str_starts_with($type, 'enum(')) {
                $pdo->exec('ALTER TABLE erp_subjects MODIFY subject_type VARCHAR(80) NOT NULL DEFAULT "Core"');
            }
        } catch (Throwable) {
            // Older deployments may not permit ALTER during a request; keep the save path usable.
        }
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

    public function saveCourseSubjectPlan(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can update course subject plans'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $course = trim((string) ($body['course'] ?? ''));
        $entries = is_array($body['entries'] ?? null) ? $body['entries'] : [];
        if ($course === '') {
            return $this->json($response, ['error' => 'Course is required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureCourseSubjectPlanSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM erp_course_subject_plans WHERE institution_id = ? AND course_name = ?')->execute([$institutionId, $course]);
            $subject = $pdo->prepare('SELECT id FROM erp_subjects WHERE institution_id = ? AND code = ? LIMIT 1');
            $insert = $pdo->prepare(
                'INSERT INTO erp_course_subject_plans (institution_id, course_name, row_no, semester_no, subject_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE subject_id = VALUES(subject_id)'
            );
            $saved = 0;
            foreach ($entries as $entry) {
                $code = strtoupper(trim((string) ($entry['subjectCode'] ?? '')));
                $rowNo = max(1, (int) ($entry['rowIndex'] ?? 0) + 1);
                $semester = max(1, min(6, (int) ($entry['semester'] ?? 0)));
                if ($code === '' || $semester <= 0) {
                    continue;
                }
                $subject->execute([$institutionId, $code]);
                $subjectId = (int) $subject->fetchColumn();
                if ($subjectId <= 0) {
                    continue;
                }
                $insert->execute([$institutionId, $course, $rowNo, $semester, $subjectId]);
                $saved++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->audit($pdo, $request, 'masters', 'course_subject_plan_saved', 'erp_course_subject_plans', $course, ['entries' => $saved]);
        $rows = $pdo->prepare(
            'SELECT p.course_name, p.row_no, p.semester_no, p.subject_id, sub.code, sub.name, sub.subject_type
             FROM erp_course_subject_plans p
             JOIN erp_subjects sub ON sub.id = p.subject_id
             WHERE p.institution_id = ? AND p.course_name = ?
             ORDER BY p.row_no, p.semester_no'
        );
        $rows->execute([$institutionId, $course]);
        return $this->json($response, ['data' => $rows->fetchAll(), 'saved' => $saved], 201);
    }

    public function saveClassSubjectPlan(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can update class subject plans'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $sectionId = (int) ($body['sectionId'] ?? 0);
        $semester = max(1, min(6, (int) ($body['semester'] ?? 1)));
        $maxSubjects = max(1, min(30, (int) ($body['maxSubjects'] ?? 8)));
        $shouldUpdateEntries = array_key_exists('entries', $body);
        $entries = is_array($body['entries'] ?? null) ? $body['entries'] : [];
        if ($sectionId <= 0) {
            return $this->json($response, ['error' => 'Section is required'], 422);
        }
        $pdo = $this->db->pdo();
        $institutionId = $this->activeInstitutionId($pdo);
        $this->ensureSubjectSelectionLimitSchema($pdo);

        $pdo->beginTransaction();
        try {
            $limit = $pdo->prepare(
                'INSERT INTO erp_section_subject_limits (section_id, semester_no, max_subjects)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE max_subjects = VALUES(max_subjects)'
            );
            $limit->execute([$sectionId, $semester, $maxSubjects]);
            if ($shouldUpdateEntries) {
                $pdo->prepare('DELETE FROM erp_section_subjects WHERE section_id = ? AND semester_no = ?')->execute([$sectionId, $semester]);
            }
            $subject = $pdo->prepare('SELECT id FROM erp_subjects WHERE institution_id = ? AND code = ? LIMIT 1');
            $insert = $pdo->prepare(
                'INSERT INTO erp_section_subjects (section_id, subject_id, semester_no, is_mandatory)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE semester_no = VALUES(semester_no), is_mandatory = VALUES(is_mandatory)'
            );
            $seen = [];
            $saved = 0;
            foreach ($shouldUpdateEntries ? $entries : [] as $entry) {
                $code = strtoupper(trim((string) ($entry['subjectCode'] ?? '')));
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $subject->execute([$institutionId, $code]);
                $subjectId = (int) $subject->fetchColumn();
                if ($subjectId <= 0) {
                    continue;
                }
                $mandatory = ((string) ($entry['mandatory'] ?? 'Yes')) === 'Yes' ? 1 : 0;
                $insert->execute([$sectionId, $subjectId, $semester, $mandatory]);
                $saved++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->audit($pdo, $request, 'masters', 'class_subject_plan_saved', 'erp_section_subjects', (string) $sectionId, ['semester' => $semester, 'entries' => $saved, 'maxSubjects' => $maxSubjects]);
        $rows = $pdo->prepare(
            'SELECT ss.section_id, ss.subject_id, ss.semester_no, ss.is_mandatory, sub.code, sub.name, sub.subject_type
             FROM erp_section_subjects ss
             JOIN erp_subjects sub ON sub.id = ss.subject_id
             WHERE ss.section_id = ? AND ss.semester_no = ?
             ORDER BY sub.subject_type, sub.name'
        );
        $rows->execute([$sectionId, $semester]);
        return $this->json($response, ['data' => $rows->fetchAll(), 'saved' => $saved, 'maxSubjects' => $maxSubjects], 201);
    }

    public function subjectSetup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->masters($request, $response);
    }

    public function saveSubjectPapers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can configure subject papers'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $course = trim((string) ($body['course'] ?? ''));
        $yearName = trim((string) ($body['yearName'] ?? $body['year'] ?? ''));
        $semester = max(1, min(12, (int) ($body['semester'] ?? 1)));
        $subjectCode = strtoupper(trim((string) ($body['subjectCode'] ?? '')));
        $papers = is_array($body['papers'] ?? null) ? $body['papers'] : [];
        if ($course === '' || $yearName === '' || $subjectCode === '') {
            return $this->json($response, ['error' => 'Course, year and subject are required for paper setup'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureDynamicSubjectPlanSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        $subjectId = $this->subjectIdByCode($pdo, $institutionId, $subjectCode);
        if ($subjectId <= 0) {
            return $this->json($response, ['error' => 'Subject not found in master list'], 422);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM erp_subject_papers WHERE institution_id = ? AND course_name = ? AND year_name = ? AND semester_no = ? AND subject_id = ?')
                ->execute([$institutionId, $course, $yearName, $semester, $subjectId]);
            $insert = $pdo->prepare(
                'INSERT INTO erp_subject_papers (institution_id, course_name, year_name, semester_no, subject_id, paper_code, paper_name, paper_type, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $saved = 0;
            foreach ($papers as $index => $paper) {
                $paperCode = strtoupper(trim((string) ($paper['paperCode'] ?? $paper['paper_code'] ?? '')));
                $paperName = trim((string) ($paper['paperName'] ?? $paper['paper_name'] ?? ''));
                $paperType = trim((string) ($paper['paperType'] ?? $paper['paper_type'] ?? 'Theory')) ?: 'Theory';
                if ($paperCode === '' || $paperName === '') {
                    continue;
                }
                $insert->execute([$institutionId, $course, $yearName, $semester, $subjectId, $paperCode, $paperName, $paperType, $index + 1]);
                $saved++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->audit($pdo, $request, 'subjects', 'subject_papers_saved', 'erp_subject_papers', $course . ':' . $yearName . ':' . $subjectCode, ['semester' => $semester, 'saved' => $saved]);
        return $this->json($response, ['data' => $this->subjectSetupPayload($pdo), 'saved' => $saved], 201);
    }

    public function saveCourseYearSubjectGroups(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can configure course-year subject groups'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $course = trim((string) ($body['course'] ?? ''));
        $yearName = trim((string) ($body['yearName'] ?? $body['year'] ?? ''));
        $groups = is_array($body['groups'] ?? null) ? $body['groups'] : [];
        if ($course === '' || $yearName === '') {
            return $this->json($response, ['error' => 'Course and year are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureDynamicSubjectPlanSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        $upsert = $pdo->prepare(
            'INSERT INTO erp_course_year_subject_groups
             (institution_id, course_name, year_name, group_key, group_name, parent_group_key, sort_order, selection_type, min_select, max_select, is_active, locked_after_admission, is_major_source, creates_major_lock, allow_student_choice)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE group_name = VALUES(group_name), parent_group_key = VALUES(parent_group_key), sort_order = VALUES(sort_order), selection_type = VALUES(selection_type), min_select = VALUES(min_select), max_select = VALUES(max_select), is_active = VALUES(is_active), locked_after_admission = VALUES(locked_after_admission), is_major_source = VALUES(is_major_source), creates_major_lock = VALUES(creates_major_lock), allow_student_choice = VALUES(allow_student_choice)'
        );
        $saved = 0;
        foreach ($groups as $index => $group) {
            $name = trim((string) ($group['groupName'] ?? $group['group_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = trim((string) ($group['groupKey'] ?? $group['group_key'] ?? '')) ?: $this->subjectGroupKey($name);
            $parentKey = trim((string) ($group['parentGroupKey'] ?? $group['parent_group_key'] ?? '')) ?: null;
            $selectionType = trim((string) ($group['selectionType'] ?? $group['selection_type'] ?? 'select_one')) ?: 'select_one';
            $upsert->execute([
                $institutionId,
                $course,
                $yearName,
                $key,
                $name,
                $parentKey,
                (int) ($group['sortOrder'] ?? $group['sort_order'] ?? $index + 1),
                $selectionType,
                max(0, (int) ($group['minSelect'] ?? $group['min_select'] ?? 0)),
                max(0, (int) ($group['maxSelect'] ?? $group['max_select'] ?? 1)),
                !empty($group['isActive'] ?? $group['is_active'] ?? true) ? 1 : 0,
                !empty($group['lockedAfterAdmission'] ?? $group['locked_after_admission'] ?? false) ? 1 : 0,
                !empty($group['isMajorSource'] ?? $group['is_major_source'] ?? false) ? 1 : 0,
                !empty($group['createsMajorLock'] ?? $group['creates_major_lock'] ?? false) ? 1 : 0,
                !array_key_exists('allowStudentChoice', $group) || !empty($group['allowStudentChoice']) ? 1 : 0,
            ]);
            $saved++;
        }
        $this->audit($pdo, $request, 'subjects', 'course_year_groups_saved', 'erp_course_year_subject_groups', $course . ':' . $yearName, ['saved' => $saved]);
        return $this->json($response, ['data' => $this->subjectSetupPayload($pdo), 'saved' => $saved], 201);
    }

    public function saveCourseYearGroupSubjects(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal'], true)) {
            return $this->json($response, ['error' => 'Only admin or principal can assign group subjects'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $course = trim((string) ($body['course'] ?? ''));
        $yearName = trim((string) ($body['yearName'] ?? $body['year'] ?? ''));
        $groupKey = trim((string) ($body['groupKey'] ?? $body['group_key'] ?? ''));
        $entries = is_array($body['entries'] ?? null) ? $body['entries'] : [];
        if ($course === '' || $yearName === '' || $groupKey === '') {
            return $this->json($response, ['error' => 'Course, year and group are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureDynamicSubjectPlanSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        $groupId = $this->courseYearGroupId($pdo, $institutionId, $course, $yearName, $groupKey);
        if ($groupId <= 0) {
            return $this->json($response, ['error' => 'Subject group not found. Save group rules first.'], 422);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM erp_course_year_group_subjects WHERE group_id = ?')->execute([$groupId]);
            $subject = $pdo->prepare('SELECT id, name FROM erp_subjects WHERE institution_id = ? AND code = ? LIMIT 1');
            $removeDuplicateSemesterSubject = $pdo->prepare(
                'DELETE gs FROM erp_course_year_group_subjects gs
                 JOIN erp_course_year_subject_groups g ON g.id = gs.group_id
                 WHERE g.institution_id = ? AND g.course_name = ? AND g.year_name = ?
                   AND gs.semester_no = ? AND gs.subject_id = ? AND gs.group_id <> ?'
            );
            $insert = $pdo->prepare(
                'INSERT INTO erp_course_year_group_subjects (group_id, semester_no, subject_id, subject_family_key, sort_order, is_default)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $saved = 0;
            foreach ($entries as $index => $entry) {
                $subjectCode = strtoupper(trim((string) ($entry['subjectCode'] ?? $entry['subject_code'] ?? '')));
                $semester = max(1, min(12, (int) ($entry['semester'] ?? $entry['semester_no'] ?? 1)));
                if ($subjectCode === '') {
                    continue;
                }
                $subject->execute([$institutionId, $subjectCode]);
                $subjectRow = $subject->fetch();
                if (!$subjectRow) {
                    continue;
                }
                $subjectId = (int) $subjectRow['id'];
                $removeDuplicateSemesterSubject->execute([$institutionId, $course, $yearName, $semester, $subjectId, $groupId]);
                $family = trim((string) ($entry['subjectFamilyKey'] ?? $entry['subject_family_key'] ?? '')) ?: $this->subjectGroupKey((string) $subjectRow['name']);
                $insert->execute([$groupId, $semester, $subjectId, $family, (int) ($entry['sortOrder'] ?? $entry['sort_order'] ?? $index + 1), !empty($entry['isDefault'] ?? $entry['is_default'] ?? false) ? 1 : 0]);
                $saved++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->audit($pdo, $request, 'subjects', 'course_year_group_subjects_saved', 'erp_course_year_group_subjects', (string) $groupId, ['saved' => $saved]);
        return $this->json($response, ['data' => $this->subjectSetupPayload($pdo), 'saved' => $saved], 201);
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
        $nameParts = array_filter([$course, $rawName]);
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

        $updatedAdmission = null;
        if (strcasecmp($module, 'Admission follow-up') === 0) {
            $applicantId = (int) ($body['applicantId'] ?? 0);
            if ($applicantId > 0) {
                $app = $pdo->prepare('SELECT details_json FROM erp_admission_applications WHERE id = ? LIMIT 1');
                $app->execute([$applicantId]);
                $details = json_decode((string) ($app->fetchColumn() ?: '{}'), true) ?: [];
                $followUps = is_array($details['follow_ups'] ?? null) ? $details['follow_ups'] : [];
                $followUps[] = [
                    'at' => date('Y-m-d H:i:s'),
                    'outcome' => $status,
                    'mode' => trim((string) ($body['contactMode'] ?? '')),
                    'nextDate' => trim((string) ($body['nextDate'] ?? '')),
                    'counsellor' => trim((string) ($body['counsellor'] ?? '')),
                    'remark' => trim((string) ($body['remark'] ?? '')),
                    'note' => trim((string) ($body['note'] ?? '')),
                    'recordId' => $recordId,
                ];
                $details['follow_ups'] = $followUps;
                $details['current_followup_status'] = $status;
                $nextDate = trim((string) ($body['nextDate'] ?? '')) ?: null;
                $note = trim((string) ($body['note'] ?? $body['remark'] ?? '')) ?: $status;
                $update = $pdo->prepare('UPDATE erp_admission_applications SET follow_up_at = ?, follow_up_note = ?, details_json = ? WHERE id = ?');
                $update->execute([$nextDate, $note, json_encode($details, JSON_THROW_ON_ERROR), $applicantId]);
                $updatedAdmission = $this->findAdmission($pdo, $applicantId);
            }
        }

        $responsePayload = [
            'data' => [
                'id' => $recordId,
                'module' => $module,
                'name' => $name,
                'code' => $code,
                'status' => $status,
                'savedAt' => $createdAt,
                'payload' => $body,
            ],
        ];
        if ($updatedAdmission !== null) {
            $responsePayload['admission'] = $updatedAdmission;
        }

        return $this->json($response, $responsePayload, 201);
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

    public function scholarships(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'teacher', 'clerk', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Scholarship register is available only to office and teaching users'], 403);
        }
        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        return $this->json($response, ['data' => $this->scholarshipPayload($pdo)]);
    }

    public function saveScholarshipForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'teacher', 'clerk'], true)) {
            return $this->json($response, ['error' => 'Only teacher or office users can verify scholarship forms'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $studentId = (int) ($body['studentId'] ?? 0);
        $status = trim((string) ($body['status'] ?? 'Submitted'));
        $scheme = trim((string) ($body['scheme'] ?? 'Government scholarship')) ?: 'Government scholarship';
        $formNumber = trim((string) ($body['formNumber'] ?? $body['form_no'] ?? ''));
        $note = trim((string) ($body['note'] ?? ''));
        if ($studentId <= 0) {
            return $this->json($response, ['error' => 'Student selection is required'], 422);
        }
        if (strtolower($status) !== 'not submitted' && $formNumber === '') {
            return $this->json($response, ['error' => 'Scholarship form number is required before marking the form submitted'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureSavedRecordsSchema($pdo);
        $student = $this->studentBrief($pdo, $studentId);
        if (!$student) {
            return $this->json($response, ['error' => 'Student not found'], 404);
        }
        $payload = [
            'studentId' => $studentId,
            'studentName' => $student['name'],
            'admissionNo' => $student['admission_no'],
            'classId' => (int) $student['class_id'],
            'className' => $student['class_name'],
            'sectionName' => $student['section_name'],
            'category' => (string) ($student['category'] ?? 'OPEN'),
            'scheme' => $scheme,
            'formNumber' => $formNumber,
            'status' => $status,
            'note' => $note,
            'verifiedByRole' => $role,
            'verifiedAt' => date('Y-m-d H:i:s'),
        ] + $this->activeAcademicYearMeta($pdo, (int) $student['institution_id']);
        $id = 'SCH-' . $studentId . '-' . date('Ymd-His');
        $st = $pdo->prepare('INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $userId = $request->getAttribute('user_id');
        $st->execute([$id, $userId !== null ? (int) $userId : null, 'Scholarship form', (string) $student['name'], 'SCH-' . $studentId, $status, json_encode($payload, JSON_THROW_ON_ERROR)]);
        $this->audit($pdo, $request, 'scholarship', 'form_status_saved', 'erp_saved_records', $id, $payload);
        return $this->json($response, ['data' => $this->scholarshipPayload($pdo), 'record' => $payload], 201);
    }

    public function receiveScholarshipPayment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can receive scholarship amount'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $studentId = (int) ($body['studentId'] ?? 0);
        $amount = (float) ($body['amount'] ?? 0);
        $scheme = trim((string) ($body['scheme'] ?? 'Government scholarship')) ?: 'Government scholarship';
        $reference = trim((string) ($body['reference'] ?? '')) ?: null;
        if ($studentId <= 0 || $amount <= 0) {
            return $this->json($response, ['error' => 'Student and scholarship amount are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $pdo->beginTransaction();
        try {
            $invoice = $this->openInvoiceForStudent($pdo, $studentId);
            if (!$invoice) {
                $invoice = $this->createFeeInvoiceForStudent($pdo, $studentId, 'Scholarship adjustment', $amount);
            }
            $balance = max((float) $invoice['amount'] - (float) $invoice['discount_amount'] - (float) $invoice['paid_amount'], 0);
            $posted = min($amount, $balance > 0 ? $balance : $amount);
            $receiptNo = 'SCH-RCT-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_payments') + 1), 4, '0', STR_PAD_LEFT);
            $feeHead = 'Scholarship - ' . $scheme;
            $pdo->prepare('INSERT INTO erp_fee_payments (invoice_id, receipt_no, fee_head, amount, method, paid_at, reference_no) VALUES (?, ?, ?, ?, ?, NOW(), ?)')
                ->execute([(int) $invoice['id'], $receiptNo, $feeHead, $posted, 'government_scholarship', $reference]);
            $newPaid = (float) $invoice['paid_amount'] + $posted;
            $totalDue = (float) $invoice['amount'] - (float) $invoice['discount_amount'];
            $status = $newPaid >= $totalDue ? 'paid' : 'partial';
            $pdo->prepare('UPDATE erp_fee_invoices SET paid_amount = ?, status = ? WHERE id = ?')->execute([$newPaid, $status, (int) $invoice['id']]);
            $this->saveScholarshipLedgerRecord($pdo, $request, $studentId, $scheme, $posted, $receiptNo, $reference);
            $this->audit($pdo, $request, 'scholarship', 'government_amount_received', 'erp_fee_payments', $receiptNo, ['student_id' => $studentId, 'amount' => $posted, 'scheme' => $scheme]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $this->json($response, ['data' => $this->financePayload($pdo), 'scholarships' => $this->scholarshipPayload($pdo), 'receiptNo' => $receiptNo], 201);
    }

    public function hr(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'hod', 'teacher', 'clerk', 'accountant', 'student'], true)) {
            return $this->json($response, ['error' => 'HR and leave module access is not available for this role'], 403);
        }
        $pdo = $this->db->pdo();
        $this->ensureHrSchema($pdo);
        return $this->json($response, ['data' => $this->hrPayload($pdo, $request)]);
    }

    public function saveDepartment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only admin, principal or accountant can manage departments'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $code = strtoupper(trim((string) ($body['code'] ?? preg_replace('/[^A-Z0-9]+/', '', $name))));
        $hodUserId = (int) ($body['hodUserId'] ?? 0) ?: null;
        $notes = trim((string) ($body['notes'] ?? '')) ?: null;
        if ($name === '') {
            return $this->json($response, ['error' => 'Department name is required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureHrSchema($pdo);
        $userId = $request->getAttribute('user_id');
        $st = $pdo->prepare('INSERT INTO erp_departments (name, code, hod_user_id, notes, created_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), hod_user_id = VALUES(hod_user_id), notes = VALUES(notes), updated_at = NOW()');
        $st->execute([$name, $code ?: null, $hodUserId, $notes, $userId !== null ? (int) $userId : null]);
        $this->audit($pdo, $request, 'hr', 'department_saved', 'erp_departments', $code ?: $name, ['name' => $name, 'hodUserId' => $hodUserId]);
        return $this->json($response, ['data' => $this->hrPayload($pdo, $request)], 201);
    }

    public function saveEmployee(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only admin, principal or accountant can register employees'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $departmentId = (int) ($body['departmentId'] ?? 0);
        $designation = trim((string) ($body['designation'] ?? ''));
        $employeeType = trim((string) ($body['employeeType'] ?? 'Permanent aided')) ?: 'Permanent aided';
        $staffType = trim((string) ($body['staffType'] ?? 'Teaching')) ?: 'Teaching';
        if ($name === '' || $departmentId <= 0 || $designation === '') {
            return $this->json($response, ['error' => 'Employee name, department and designation are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureHrSchema($pdo);
        $employeeNo = trim((string) ($body['employeeNo'] ?? ''));
        if ($employeeNo === '') {
            $employeeNo = 'EMP-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_employees') + 1), 4, '0', STR_PAD_LEFT);
        }
        $userId = $request->getAttribute('user_id');
        $st = $pdo->prepare('INSERT INTO erp_employees
            (employee_no, user_id, department_id, name, gender, phone, email, designation, employee_type, staff_type, joining_date, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE department_id = VALUES(department_id), name = VALUES(name), gender = VALUES(gender), phone = VALUES(phone),
                email = VALUES(email), designation = VALUES(designation), employee_type = VALUES(employee_type), staff_type = VALUES(staff_type),
                joining_date = VALUES(joining_date), status = VALUES(status), updated_at = NOW()');
        $st->execute([
            $employeeNo,
            (int) ($body['userId'] ?? 0) ?: null,
            $departmentId,
            $name,
            trim((string) ($body['gender'] ?? '')) ?: null,
            trim((string) ($body['phone'] ?? '')) ?: null,
            trim((string) ($body['email'] ?? '')) ?: null,
            $designation,
            $employeeType,
            $staffType,
            trim((string) ($body['joiningDate'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
            trim((string) ($body['status'] ?? 'Active')) ?: 'Active',
            $userId !== null ? (int) $userId : null,
        ]);
        $this->audit($pdo, $request, 'hr', 'employee_saved', 'erp_employees', $employeeNo, ['name' => $name, 'departmentId' => $departmentId]);
        return $this->json($response, ['data' => $this->hrPayload($pdo, $request), 'employeeNo' => $employeeNo], 201);
    }

    public function saveLeaveCategory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'accountant', 'clerk'], true)) {
            return $this->json($response, ['error' => 'Only office users can manage leave categories'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $code = strtoupper(trim((string) ($body['code'] ?? preg_replace('/[^A-Z0-9]+/', '', $name))));
        if ($name === '') {
            return $this->json($response, ['error' => 'Leave category name is required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureHrSchema($pdo);
        $st = $pdo->prepare('INSERT INTO erp_leave_categories (name, code, applicable_to, annual_quota, is_paid, carry_forward)
            VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), applicable_to = VALUES(applicable_to),
            annual_quota = VALUES(annual_quota), is_paid = VALUES(is_paid), carry_forward = VALUES(carry_forward), updated_at = NOW()');
        $st->execute([
            $name,
            $code ?: null,
            trim((string) ($body['applicableTo'] ?? 'All employees')) ?: 'All employees',
            (float) ($body['annualQuota'] ?? 0),
            !empty($body['isPaid']) ? 1 : 0,
            !empty($body['carryForward']) ? 1 : 0,
        ]);
        $this->audit($pdo, $request, 'hr', 'leave_category_saved', 'erp_leave_categories', $code ?: $name, ['name' => $name]);
        return $this->json($response, ['data' => $this->hrPayload($pdo, $request)], 201);
    }

    public function applyLeave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $applicantType = trim((string) ($body['applicantType'] ?? 'employee')) ?: 'employee';
        $from = trim((string) ($body['fromDate'] ?? ''));
        $to = trim((string) ($body['toDate'] ?? $from));
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($from === '' || $to === '' || $reason === '') {
            return $this->json($response, ['error' => 'Leave from date, to date and reason are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureHrSchema($pdo);
        $days = max(1, (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1);
        $employeeId = (int) ($body['employeeId'] ?? 0) ?: null;
        $studentId = (int) ($body['studentId'] ?? 0) ?: null;
        $categoryId = (int) ($body['categoryId'] ?? 0) ?: null;
        if ($applicantType === 'student' && !$studentId) {
            return $this->json($response, ['error' => 'Student selection is required for student leave'], 422);
        }
        if ($applicantType !== 'student' && !$employeeId) {
            $employeeId = $this->employeeIdForUser($pdo, (int) ($request->getAttribute('user_id') ?? 0));
        }
        if ($applicantType !== 'student' && !$employeeId) {
            return $this->json($response, ['error' => 'Employee selection is required'], 422);
        }
        $st = $pdo->prepare('INSERT INTO erp_leave_applications
            (applicant_type, employee_id, student_id, category_id, from_date, to_date, days, reason, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Pending", ?)');
        $userId = $request->getAttribute('user_id');
        $st->execute([$applicantType, $employeeId, $studentId, $categoryId, $from, $to, $days, $reason, $userId !== null ? (int) $userId : null]);
        $leaveId = (int) $pdo->lastInsertId();
        $this->audit($pdo, $request, 'hr', 'leave_applied', 'erp_leave_applications', (string) $leaveId, ['days' => $days, 'type' => $applicantType]);
        return $this->json($response, ['data' => $this->hrPayload($pdo, $request), 'leaveId' => $leaveId], 201);
    }

    public function reviewLeave(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'hod', 'teacher', 'clerk'], true)) {
            return $this->json($response, ['error' => 'This role cannot approve leave'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $status = trim((string) ($body['status'] ?? 'Approved')) ?: 'Approved';
        if (!in_array($status, ['Approved', 'Rejected', 'Cancelled'], true)) {
            return $this->json($response, ['error' => 'Invalid leave status'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureHrSchema($pdo);
        $st = $pdo->prepare('SELECT * FROM erp_leave_applications WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $leave = $st->fetch();
        if (!$leave) {
            return $this->json($response, ['error' => 'Leave application not found'], 404);
        }
        if ($role === 'teacher' && (string) $leave['applicant_type'] !== 'student') {
            return $this->json($response, ['error' => 'Teachers can approve student leave only'], 403);
        }
        if (in_array($role, ['hod', 'clerk'], true) && (string) $leave['applicant_type'] === 'student') {
            return $this->json($response, ['error' => 'Student leave should be approved by teacher/admin'], 403);
        }
        $approverId = $request->getAttribute('user_id');
        $pdo->prepare('UPDATE erp_leave_applications SET status = ?, review_note = ?, approver_user_id = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$status, trim((string) ($body['note'] ?? '')) ?: null, $approverId !== null ? (int) $approverId : null, $id]);
        if ($status === 'Approved' && (int) ($leave['employee_id'] ?? 0) > 0 && (int) ($leave['category_id'] ?? 0) > 0) {
            $this->upsertLeaveBalance($pdo, (int) $leave['employee_id'], (int) $leave['category_id'], (int) date('Y', strtotime((string) $leave['from_date'])), (float) $leave['days']);
        }
        $this->audit($pdo, $request, 'hr', 'leave_reviewed', 'erp_leave_applications', (string) $id, ['status' => $status]);
        return $this->json($response, ['data' => $this->hrPayload($pdo, $request)], 200);
    }

    public function saveSalaryStructure(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can manage salary structures'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $employeeId = (int) ($body['employeeId'] ?? 0);
        $basic = (float) ($body['basic'] ?? 0);
        if ($employeeId <= 0 || $basic <= 0) {
            return $this->json($response, ['error' => 'Employee and basic salary are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureHrSchema($pdo);
        $fields = $this->salaryFieldsFromBody($body);
        $gross = $basic + $fields['ta'] + $fields['da'] + $fields['hra'] + $fields['other_allowance'];
        $deductions = $fields['pf'] + $fields['esi'] + $fields['loan'] + $fields['other_deduction'];
        $net = max($gross - $deductions, 0);
        $st = $pdo->prepare('INSERT INTO erp_salary_structures
            (employee_id, basic, pf, esi, loan, ta, da, hra, other_allowance, other_deduction, gross, net, effective_from, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE basic = VALUES(basic), pf = VALUES(pf), esi = VALUES(esi), loan = VALUES(loan),
            ta = VALUES(ta), da = VALUES(da), hra = VALUES(hra), other_allowance = VALUES(other_allowance),
            other_deduction = VALUES(other_deduction), gross = VALUES(gross), net = VALUES(net), updated_at = NOW()');
        $userId = $request->getAttribute('user_id');
        $st->execute([$employeeId, $basic, $fields['pf'], $fields['esi'], $fields['loan'], $fields['ta'], $fields['da'], $fields['hra'], $fields['other_allowance'], $fields['other_deduction'], $gross, $net, trim((string) ($body['effectiveFrom'] ?? date('Y-m-d'))) ?: date('Y-m-d'), $userId !== null ? (int) $userId : null]);
        $this->audit($pdo, $request, 'hr', 'salary_structure_saved', 'erp_salary_structures', (string) $employeeId, ['gross' => $gross, 'net' => $net]);
        return $this->json($response, ['data' => $this->hrPayload($pdo, $request)], 201);
    }

    public function generateSalarySlip(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can generate salary slips'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $employeeId = (int) ($body['employeeId'] ?? 0);
        $month = trim((string) ($body['salaryMonth'] ?? date('Y-m')));
        if ($employeeId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $this->json($response, ['error' => 'Employee and salary month are required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureHrSchema($pdo);
        $st = $pdo->prepare('SELECT * FROM erp_salary_structures WHERE employee_id = ? ORDER BY effective_from DESC, id DESC LIMIT 1');
        $st->execute([$employeeId]);
        $structure = $st->fetch();
        if (!$structure) {
            return $this->json($response, ['error' => 'Salary structure is not configured for this employee'], 422);
        }
        $slipNo = 'SAL-' . str_replace('-', '', $month) . '-' . str_pad((string) $employeeId, 4, '0', STR_PAD_LEFT);
        $allowance = (float) $structure['ta'] + (float) $structure['da'] + (float) $structure['hra'] + (float) $structure['other_allowance'];
        $deduction = (float) $structure['pf'] + (float) $structure['esi'] + (float) $structure['loan'] + (float) $structure['other_deduction'];
        $gross = (float) $structure['basic'] + $allowance;
        $net = max($gross - $deduction, 0);
        $userId = $request->getAttribute('user_id');
        $pdo->prepare('INSERT INTO erp_salary_slips
            (slip_no, employee_id, salary_month, basic, allowance_total, deduction_total, gross, net, status, generated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Generated", ?)
            ON DUPLICATE KEY UPDATE basic = VALUES(basic), allowance_total = VALUES(allowance_total), deduction_total = VALUES(deduction_total),
            gross = VALUES(gross), net = VALUES(net), status = "Generated", updated_at = NOW()')
            ->execute([$slipNo, $employeeId, $month, (float) $structure['basic'], $allowance, $deduction, $gross, $net, $userId !== null ? (int) $userId : null]);
        $this->audit($pdo, $request, 'hr', 'salary_slip_generated', 'erp_salary_slips', $slipNo, ['employeeId' => $employeeId, 'month' => $month, 'net' => $net]);
        return $this->json($response, ['data' => $this->hrPayload($pdo, $request), 'slipNo' => $slipNo], 201);
    }

    public function studentPortal(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['student', 'admin'], true)) {
            return $this->json($response, ['error' => 'Student portal access only'], 403);
        }

        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $email = trim((string) $request->getAttribute('user_email'));
        $digits = preg_replace('/\D+/', '', $email) ?: '';

        $app = null;
        if ($digits !== '') {
            $st = $pdo->prepare(
                'SELECT a.*, c.name AS class_name
                 FROM erp_admission_applications a
                 JOIN erp_classes c ON c.id = a.target_class_id
                 WHERE REPLACE(REPLACE(REPLACE(COALESCE(a.aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(a.details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ?
                 ORDER BY a.id DESC
                 LIMIT 1'
            );
            $st->execute([$digits]);
            $app = $st->fetch() ?: null;
        }
        if (!$app && $email !== '') {
            $st = $pdo->prepare(
                'SELECT a.*, c.name AS class_name
                 FROM erp_admission_applications a
                 JOIN erp_classes c ON c.id = a.target_class_id
                 WHERE a.email = ?
                 ORDER BY a.id DESC
                 LIMIT 1'
            );
            $st->execute([$email]);
            $app = $st->fetch() ?: null;
        }

        $student = null;
        if ($app) {
            $st = $pdo->prepare(
                "SELECT s.id, s.admission_no, s.first_name, s.last_name, s.gender, s.date_of_birth, s.email, s.phone, s.status, s.admitted_on,
                        c.name AS class_name, sec.name AS section_name
                 FROM erp_students s
                 JOIN erp_classes c ON c.id = s.class_id
                 JOIN erp_sections sec ON sec.id = s.section_id
                 WHERE s.class_id = ?
                   AND (
                    (s.phone IS NOT NULL AND s.phone <> '' AND s.phone = ?)
                    OR (s.email IS NOT NULL AND s.email <> '' AND s.email = ?)
                   )
                 ORDER BY s.id DESC
                 LIMIT 1"
            );
            $st->execute([(int) $app['target_class_id'], (string) $app['phone'], (string) $app['email']]);
            $student = $st->fetch() ?: null;
        }
        if (!$student && $email !== '') {
            $st = $pdo->prepare(
                "SELECT s.id, s.admission_no, s.first_name, s.last_name, s.gender, s.date_of_birth, s.email, s.phone, s.status, s.admitted_on,
                        c.name AS class_name, sec.name AS section_name
                 FROM erp_students s
                 JOIN erp_classes c ON c.id = s.class_id
                 JOIN erp_sections sec ON sec.id = s.section_id
                 WHERE s.email = ? OR s.admission_no = ?
                 ORDER BY s.id DESC
                 LIMIT 1"
            );
            $st->execute([$email, $email]);
            $student = $st->fetch() ?: null;
        }

        $studentId = (int) ($student['id'] ?? 0);
        $invoices = [];
        $payments = [];
        if ($studentId > 0) {
            $st = $pdo->prepare(
                "SELECT i.id, i.invoice_no, f.name AS fee_head, i.amount, i.discount_amount, i.paid_amount,
                        (i.amount - i.discount_amount - i.paid_amount) AS balance, i.status, i.due_on
                 FROM erp_fee_invoices i
                 JOIN erp_fee_plans f ON f.id = i.fee_plan_id
                 WHERE i.student_id = ?
                 ORDER BY i.due_on, i.id"
            );
            $st->execute([$studentId]);
            $invoices = $st->fetchAll();

            $st = $pdo->prepare(
                "SELECT p.receipt_no, COALESCE(p.fee_head, f.name) AS fee_head, p.amount, p.method, p.paid_at, p.reference_no,
                        i.invoice_no, (i.amount - i.discount_amount) AS fee_head_total, i.paid_amount AS fee_head_paid,
                        (i.amount - i.discount_amount - i.paid_amount) AS balance_after,
                        (
                            SELECT COALESCE(SUM(ii.amount - ii.discount_amount - ii.paid_amount), 0)
                            FROM erp_fee_invoices ii
                            WHERE ii.student_id = s.id
                        ) AS total_balance_after,
                        s.admission_no, CONCAT(s.first_name, ' ', s.last_name) AS student_name, c.name AS class_name
                 FROM erp_fee_payments p
                 JOIN erp_fee_invoices i ON i.id = p.invoice_id
                 JOIN erp_students s ON s.id = i.student_id
                 JOIN erp_classes c ON c.id = s.class_id
                 JOIN erp_fee_plans f ON f.id = i.fee_plan_id
                 WHERE i.student_id = ?
                 ORDER BY p.paid_at DESC, p.id DESC"
            );
            $st->execute([$studentId]);
            $payments = $st->fetchAll();
        }

        $details = $app ? (json_decode((string) ($app['details_json'] ?? '{}'), true) ?: []) : [];
        $portalSubjects = $this->studentPortalSubjects($pdo, $details, $student ? (int) $student['id'] : 0);
        $scholarship = $studentId > 0 ? $this->scholarshipStatusForStudent($pdo, $studentId) : null;
        $documentStatuses = [];
        foreach ($details as $key => $value) {
            if (str_starts_with((string) $key, 'Document:')) {
                $documentStatuses[] = ['name' => substr((string) $key, 9), 'status' => (string) $value];
            }
        }
        $verification = is_array($details['document_verification'] ?? null) ? $details['document_verification'] : [];
        $uploadedDocuments = is_array($details['uploaded_documents'] ?? null) ? $details['uploaded_documents'] : [];
        $originals = is_array($details['original_documents'] ?? null) ? $details['original_documents'] : [];
        $certificates = array_values(array_filter($payments, static function (array $payment): bool {
            $head = strtolower((string) ($payment['fee_head'] ?? ''));
            return str_contains($head, 'document') || str_contains($head, 'bonafide') || str_contains($head, 'tc') || str_contains($head, 'nirgam') || str_contains($head, 'no dues');
        }));

        $billed = array_reduce($invoices, static fn (float $sum, array $row): float => $sum + (float) $row['amount'] - (float) $row['discount_amount'], 0.0);
        $paid = array_reduce($invoices, static fn (float $sum, array $row): float => $sum + (float) $row['paid_amount'], 0.0);
        $applicationRef = $app ? ('RSL-' . date('Y') . '-' . str_pad((string) $app['id'], 4, '0', STR_PAD_LEFT)) : null;

        return $this->json($response, ['data' => [
            'student' => $student,
            'application' => $app ? $this->admissionRow([
                'id' => $app['id'],
                'applicant_name' => $app['applicant_name'],
                'guardian_name' => $app['guardian_name'],
                'phone' => $app['phone'],
                'email' => $app['email'],
                'stage' => $app['stage'],
                'score' => $app['score'],
                'source' => $app['source'],
                'aadhar_no' => $app['aadhar_no'],
                'follow_up_at' => $app['follow_up_at'],
                'follow_up_note' => $app['follow_up_note'],
                'details_json' => $app['details_json'],
                'class_name' => $app['class_name'],
                'created_at' => $app['created_at'],
            ]) : null,
            'applicationRef' => $applicationRef,
            'summary' => [
                'billed' => $billed,
                'paid' => $paid,
                'balance' => max($billed - $paid, 0),
                'receipts' => count($payments),
                'documents' => count($uploadedDocuments),
                'certificates' => count($certificates),
            ],
            'invoices' => $invoices,
            'payments' => $payments,
            'documents' => $uploadedDocuments,
            'documentChecklist' => $documentStatuses,
            'verification' => $verification,
            'originalDocuments' => $originals,
            'certificates' => $certificates,
            'studentPhotoUrl' => (string) ($details['student_photo_url'] ?? ''),
            'subjects' => $portalSubjects,
            'scholarship' => $scholarship,
        ]]);
    }

    public function uploadStudentPhoto(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['student', 'admin'], true)) {
            return $this->json($response, ['error' => 'Student portal access only'], 403);
        }
        $files = $request->getUploadedFiles();
        if (!isset($files['file'])) {
            return $this->json($response, ['error' => 'Missing file field "file"'], 422);
        }
        /** @var UploadedFileInterface $file */
        $file = $files['file'];
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'Upload failed (code ' . $file->getError() . ')'], 400);
        }
        $size = $file->getSize() ?? 0;
        if ($size > self::STUDENT_PHOTO_MAX_BYTES) {
            return $this->json($response, ['error' => 'Photo too large (max 3 MB)'], 413);
        }
        $mime = (string) $file->getClientMediaType();
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            return $this->json($response, ['error' => 'Only JPEG, PNG or WebP photo is allowed'], 415);
        }

        $pdo = $this->db->pdo();
        $email = trim((string) $request->getAttribute('user_email'));
        $digits = preg_replace('/\D+/', '', $email) ?: '';
        $app = null;
        if ($digits !== '') {
            $st = $pdo->prepare('SELECT id, details_json FROM erp_admission_applications WHERE REPLACE(REPLACE(REPLACE(COALESCE(aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$digits]);
            $app = $st->fetch() ?: null;
        }
        if (!$app && $email !== '') {
            $st = $pdo->prepare('SELECT id, details_json FROM erp_admission_applications WHERE email = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$email]);
            $app = $st->fetch() ?: null;
        }
        if (!$app) {
            return $this->json($response, ['error' => 'Admission application is not linked to this student account'], 404);
        }

        $ext = $allowed[$mime];
        $name = 'student-photo-' . (int) $app['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $root = dirname(__DIR__, 2);
        $dir = $root . '/public/uploads/student-photos';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->json($response, ['error' => 'Cannot create upload directory'], 500);
        }
        $file->moveTo($dir . DIRECTORY_SEPARATOR . $name);
        $url = '/uploads/student-photos/' . $name;
        $details = json_decode((string) ($app['details_json'] ?? '{}'), true) ?: [];
        $details['student_photo_url'] = $url;
        $details['student_photo_uploaded_at'] = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE erp_admission_applications SET details_json = ? WHERE id = ?')->execute([json_encode($details, JSON_THROW_ON_ERROR), (int) $app['id']]);
        $this->audit($pdo, $request, 'student', 'photo_uploaded', 'erp_admission_applications', (string) $app['id'], ['url' => $url]);
        return $this->json($response, ['data' => ['url' => $url]], 201);
    }

    public function uploadAdmissionDocument(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'principal', 'clerk', 'teacher', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only office users can upload admission documents'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        return $this->storeAdmissionDocumentUpload($request, $response, $id, $role);
    }

    public function uploadStudentPortalDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['student', 'admin'], true)) {
            return $this->json($response, ['error' => 'Student portal access only'], 403);
        }
        $pdo = $this->db->pdo();
        $app = $this->linkedStudentApplication($pdo, $request);
        if (!$app) {
            return $this->json($response, ['error' => 'Admission application is not linked to this student account'], 404);
        }
        return $this->storeAdmissionDocumentUpload($request, $response, (int) $app['id'], $role);
    }

    private function storeAdmissionDocumentUpload(ServerRequestInterface $request, ResponseInterface $response, int $applicationId, string $role): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        if (!isset($files['file'])) {
            return $this->json($response, ['error' => 'Missing file field "file"'], 422);
        }
        /** @var UploadedFileInterface $file */
        $file = $files['file'];
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'Upload failed (code ' . $file->getError() . ')'], 400);
        }
        if (($file->getSize() ?? 0) > self::ADMISSION_DOCUMENT_MAX_BYTES) {
            return $this->json($response, ['error' => 'Document too large (max 8 MB before compression)'], 413);
        }
        $body = $request->getParsedBody();
        $documentName = trim((string) (is_array($body) ? ($body['documentName'] ?? '') : ''));
        if ($documentName === '') {
            return $this->json($response, ['error' => 'Document name is required'], 422);
        }
        $custody = trim((string) (is_array($body) ? ($body['custody'] ?? 'With student') : 'With student'));
        $status = trim((string) (is_array($body) ? ($body['status'] ?? 'Uploaded') : 'Uploaded'));
        $note = trim((string) (is_array($body) ? ($body['note'] ?? '') : ''));
        $mime = strtolower((string) $file->getClientMediaType());
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'jpg', 'image/webp' => 'jpg', 'application/pdf' => 'pdf'];
        if (!isset($allowed[$mime])) {
            return $this->json($response, ['error' => 'Only image or PDF document is allowed'], 415);
        }

        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id, details_json FROM erp_admission_applications WHERE id = ?');
        $st->execute([$applicationId]);
        $app = $st->fetch();
        if (!$app) {
            return $this->json($response, ['error' => 'Admission application not found'], 404);
        }

        $stream = $file->getStream();
        $stream->rewind();
        $bytes = $stream->getContents();
        $ext = $allowed[$mime];
        if ($ext === 'jpg') {
            try {
                $bytes = $this->compressDocumentImage($bytes)['bytes'];
                $mime = 'image/jpeg';
            } catch (\Throwable $e) {
                return $this->json($response, ['error' => $e->getMessage()], 422);
            }
        } elseif (strlen($bytes) > self::ADMISSION_DOCUMENT_TARGET_BYTES) {
            return $this->json($response, ['error' => 'PDF must be under 300 KB. Please upload a compressed PDF or image scan.'], 413);
        }
        if (strlen($bytes) > self::ADMISSION_DOCUMENT_TARGET_BYTES) {
            return $this->json($response, ['error' => 'Document could not be reduced below 300 KB. Please crop more area or upload a smaller scan.'], 413);
        }

        $root = dirname(__DIR__, 2);
        $dir = $root . '/public/uploads/admission-documents';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->json($response, ['error' => 'Cannot create upload directory'], 500);
        }
        $safeHead = preg_replace('/[^A-Z0-9]+/', '-', strtoupper($documentName)) ?: 'DOC';
        $name = 'admission-doc-' . $applicationId . '-' . $safeHead . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $bytes);
        $url = '/uploads/admission-documents/' . $name;

        $details = json_decode((string) ($app['details_json'] ?? '{}'), true) ?: [];
        $uploaded = is_array($details['uploaded_documents'] ?? null) ? $details['uploaded_documents'] : [];
        $uploaded = array_values(array_filter($uploaded, static fn (array $doc): bool => (string) ($doc['name'] ?? '') !== $documentName));
        $record = [
            'name' => $documentName,
            'url' => $url,
            'original_name' => (string) $file->getClientFilename(),
            'mime' => $mime,
            'size' => strlen($bytes),
            'status' => $status,
            'custody' => $custody,
            'note' => $note,
            'uploaded_by_role' => $role,
            'uploaded_at' => date('Y-m-d H:i:s'),
        ];
        $uploaded[] = $record;
        $details['uploaded_documents'] = $uploaded;

        $masterDocs = $this->admissionDocumentNames($pdo);
        $uploadedNames = array_values(array_unique(array_filter(array_map(static fn (array $doc): string => (string) ($doc['name'] ?? ''), $uploaded))));
        $pending = array_values(array_diff($masterDocs, $uploadedNames));
        $details['document_verification'] = [
            'verified_documents' => $uploadedNames,
            'pending_documents' => $pending,
            'document_statuses' => $uploaded,
            'result' => empty($pending) ? 'All required documents uploaded' : 'Partially verified - documents pending',
            'note' => $note,
            'verified_by_role' => $role,
            'verified_at' => date('Y-m-d H:i:s'),
        ];
        $details['pending_documents'] = $pending;
        $details['admission_status'] = empty($pending) ? 'Document verified' : 'Partially verified documents';

        $stage = empty($pending) ? 'offer' : 'screening';
        $pdo->prepare('UPDATE erp_admission_applications SET stage = CASE WHEN stage IN ("fee_paid","enrolled") THEN stage ELSE ? END, details_json = ? WHERE id = ?')->execute([$stage, json_encode($details, JSON_THROW_ON_ERROR), $applicationId]);
        $this->audit($pdo, $request, 'admissions', 'document_uploaded', 'erp_admission_applications', (string) $applicationId, ['document' => $documentName, 'url' => $url, 'size' => strlen($bytes)]);
        return $this->json($response, ['data' => $record, 'application' => $this->findAdmission($pdo, $applicationId)], 201);
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
        $totalAmount = (float) ($body['totalAmount'] ?? 0);
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
                $invoice = $this->createFeeInvoiceForStudent($pdo, $studentId, $feeHead, max($totalAmount, $amount));
            }

            $balance = max((float) $invoice['amount'] - (float) $invoice['discount_amount'] - (float) $invoice['paid_amount'], 0);
            $posted = min($amount, $balance > 0 ? $balance : $amount);
            $receiptNo = 'RCT-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_fee_payments') + 1), 4, '0', STR_PAD_LEFT);

            $payment = $pdo->prepare('INSERT INTO erp_fee_payments (invoice_id, receipt_no, fee_head, amount, method, paid_at, reference_no) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $payment->execute([(int) $invoice['id'], $receiptNo, $feeHead, $posted, $method, $paidOn, $reference]);
            $documentIssue = null;
            if ($this->isTransferCertificateHead($feeHead)) {
                $documentIssue = $this->ensureTransferCertificateIssue($pdo, $studentId, $receiptNo, $feeHead, $paidOn);
            }

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
                    'document_issue' => $documentIssue,
                ],
                'finance' => $this->financePayload($pdo),
            ],
        ], 201);
    }

    public function markTcOriginalPrinted(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can print original transfer certificate'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $receiptNo = trim((string) ($body['receiptNo'] ?? ''));
        if ($receiptNo === '') {
            return $this->json($response, ['error' => 'Receipt number is required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $st = $pdo->prepare(
            "SELECT p.receipt_no, COALESCE(p.fee_head, fp.name) AS fee_head, p.paid_at, s.id AS student_id
             FROM erp_fee_payments p
             JOIN erp_fee_invoices i ON i.id = p.invoice_id
             JOIN erp_fee_plans fp ON fp.id = i.fee_plan_id
             JOIN erp_students s ON s.id = i.student_id
             WHERE p.receipt_no = ?
             LIMIT 1"
        );
        $st->execute([$receiptNo]);
        $payment = $st->fetch();
        if (!$payment || !$this->isTransferCertificateHead((string) ($payment['fee_head'] ?? ''))) {
            return $this->json($response, ['error' => 'Transfer certificate payment receipt was not found'], 404);
        }
        $issue = $this->ensureTransferCertificateIssue($pdo, (int) $payment['student_id'], $receiptNo, (string) $payment['fee_head'], (string) $payment['paid_at']);
        if (!empty($issue['original_printed_at'])) {
            return $this->json($response, ['data' => $issue, 'alreadyPrinted' => true]);
        }
        $printedAt = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE erp_document_issues SET original_printed_at = ?, updated_at = NOW() WHERE receipt_no = ? AND document_type = ?')
            ->execute([$printedAt, $receiptNo, 'Transfer Certificate']);
        $issue['original_printed_at'] = $printedAt;
        $issue['status'] = 'Original printed';
        $this->audit($pdo, $request, 'finance', 'tc_original_printed', 'erp_document_issues', $receiptNo, ['document_no' => $issue['document_no']]);
        return $this->json($response, ['data' => $issue]);
    }

    public function verifyPublicDocument(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $receiptNo = trim((string) ($args['id'] ?? ''));
        if ($receiptNo === '') {
            return $this->json($response, ['error' => 'Document reference is required'], 422);
        }

        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $st = $pdo->prepare(
            "SELECT p.receipt_no, COALESCE(p.fee_head, fp.name) AS fee_head, p.amount, p.method, p.paid_at, p.reference_no,
                    i.invoice_no, i.amount AS fee_head_total, i.paid_amount AS fee_head_paid,
                    s.id AS student_id, s.admission_no, CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                    s.date_of_birth, s.phone, s.email, c.name AS class_name, sec.name AS section_name,
                    inst.name AS institution_name
             FROM erp_fee_payments p
             JOIN erp_fee_invoices i ON i.id = p.invoice_id
             JOIN erp_fee_plans fp ON fp.id = i.fee_plan_id
             JOIN erp_students s ON s.id = i.student_id
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_sections sec ON sec.id = s.section_id
             JOIN erp_institutions inst ON inst.id = s.institution_id
             WHERE p.receipt_no = ?
             LIMIT 1"
        );
        $st->execute([$receiptNo]);
        $row = $st->fetch();
        if (!$row) {
            return $this->json($response, ['error' => 'Document or receipt was not found'], 404);
        }

        $feeHead = (string) ($row['fee_head'] ?? '');
        $isBonafide = str_contains(strtolower($feeHead), 'bonafide');
        $isTransferCertificate = $this->isTransferCertificateHead($feeHead);
        $documentType = $isBonafide ? 'Bonafide Certificate' : ($isTransferCertificate ? 'Transfer Certificate' : (str_starts_with(strtolower($feeHead), 'document -') ? trim(substr($feeHead, 10)) : 'Payment Receipt'));
        $receiptParts = explode('-', $receiptNo);
        $receiptSequence = preg_replace('/\D+/', '', (string) end($receiptParts)) ?: preg_replace('/\D+/', '', $receiptNo);
        $issue = $isTransferCertificate ? $this->ensureTransferCertificateIssue($pdo, (int) $row['student_id'], $receiptNo, $feeHead, (string) $row['paid_at']) : null;
        $certificateNo = $issue['document_no'] ?? (($isBonafide ? 'BON' : 'DOC') . '-' . date('Y', strtotime((string) $row['paid_at'])) . '-' . str_pad($receiptSequence, 4, '0', STR_PAD_LEFT));

        return $this->json($response, [
            'data' => [
                'valid' => true,
                'reference' => $receiptNo,
                'certificateNo' => $certificateNo,
                'documentType' => $documentType,
                'feeHead' => $feeHead,
                'issuedAt' => $row['paid_at'],
                'institution' => [
                    'name' => $row['institution_name'] ?: 'ROSELAND SCHOOL',
                    'organization' => 'ROSELAND SCHOOL',
                    'address' => 'Hingoli, Maharashtra',
                ],
                'student' => [
                    'id' => (int) $row['student_id'],
                    'name' => trim((string) $row['student_name']),
                    'admissionNo' => $row['admission_no'],
                    'className' => $row['class_name'],
                    'sectionName' => $row['section_name'],
                    'dateOfBirth' => $row['date_of_birth'],
                    'phone' => $row['phone'],
                    'email' => $row['email'],
                ],
                'receipt' => [
                    'receiptNo' => $row['receipt_no'],
                    'invoiceNo' => $row['invoice_no'],
                    'amount' => (float) $row['amount'],
                    'method' => $row['method'],
                    'referenceNo' => $row['reference_no'],
                    'feeHeadTotal' => (float) $row['fee_head_total'],
                    'feeHeadPaid' => (float) $row['fee_head_paid'],
                ],
                'certificateText' => $isBonafide
            ? 'This is to certify that the student named above is a bonafide student of this institution and is presently studying in the class shown in this verification record during the current academic year, as per records maintained by the school office. This certificate is issued on request for official, academic, scholarship, bank, travel, passport or other lawful administrative purposes.'
                    : ($isTransferCertificate
                        ? 'This reference verifies the Transfer Certificate issued by the institution after fee payment and office record verification.'
                        : 'This document reference verifies the official payment receipt generated by the institution.'),
            ],
        ]);
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

    public function deleteFeeHead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can remove fee heads'], 403);
        }
        $feePlanId = (int) ($args['id'] ?? 0);
        if ($feePlanId <= 0) {
            return $this->json($response, ['error' => 'Valid fee head id is required'], 422);
        }
        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $fee = $pdo->prepare('SELECT id, class_id, name, amount FROM erp_fee_plans WHERE id = ? LIMIT 1');
        $fee->execute([$feePlanId]);
        $feeRow = $fee->fetch();
        if (!$feeRow) {
            return $this->json($response, ['error' => 'Fee head not found'], 404);
        }
        $paidCount = $pdo->prepare(
            'SELECT COUNT(*)
             FROM erp_fee_invoices i
             JOIN erp_fee_payments p ON p.invoice_id = i.id
             WHERE i.fee_plan_id = ?'
        );
        $paidCount->execute([$feePlanId]);
        if ((int) $paidCount->fetchColumn() > 0) {
            return $this->json($response, ['error' => 'This fee head has payment receipts. It cannot be deleted; set amount to 0 or create a correction entry instead.'], 409);
        }

        $pdo->beginTransaction();
        try {
            $invoiceCount = $pdo->prepare('SELECT COUNT(*) FROM erp_fee_invoices WHERE fee_plan_id = ?');
            $invoiceCount->execute([$feePlanId]);
            $removedInvoices = (int) $invoiceCount->fetchColumn();
            $pdo->prepare('DELETE FROM erp_fee_invoices WHERE fee_plan_id = ?')->execute([$feePlanId]);
            $pdo->prepare('DELETE FROM erp_fee_plans WHERE id = ?')->execute([$feePlanId]);
            $this->audit($pdo, $request, 'finance', 'fee_head_deleted', 'erp_fee_plans', (string) $feePlanId, [
                'class_id' => (int) ($feeRow['class_id'] ?? 0),
                'fee_head' => (string) $feeRow['name'],
                'amount' => (float) $feeRow['amount'],
                'removedInvoices' => $removedInvoices,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->json($response, ['data' => $this->financePayload($pdo), 'removedInvoices' => $removedInvoices]);
    }

    public function saveFeeCategoryMatrix(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can manage fee category setup'], 403);
        }
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $classId = (int) ($body['classId'] ?? 0);
        $entries = is_array($body['entries'] ?? null) ? $body['entries'] : [];
        if ($classId <= 0 || $entries === []) {
            return $this->json($response, ['error' => 'Class and fee category entries are required'], 422);
        }
        $allowedCategories = [
            'GOI Scholarship' => true,
            'Granted Full Fee' => true,
            'Non-Grant Full Fee' => true,
        ];
        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $institutionId = (int) $pdo->query('SELECT institution_id FROM erp_classes WHERE id = ' . $classId . ' LIMIT 1')->fetchColumn();
        if ($institutionId <= 0) {
            return $this->json($response, ['error' => 'Selected class was not found'], 404);
        }
        $academicYearId = $this->activeAcademicYearId($pdo, $institutionId);
        $dueOn = date('Y-m-d');
        $saved = 0;
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO erp_fee_plans (institution_id, academic_year_id, class_id, name, amount, due_on)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_on = VALUES(due_on)'
            );
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $category = trim((string) ($entry['category'] ?? ''));
                $feeHead = trim((string) ($entry['feeHead'] ?? ''));
                $amount = (float) ($entry['amount'] ?? 0);
                if (!isset($allowedCategories[$category]) || $feeHead === '' || $amount < 0) {
                    continue;
                }
                $planName = $category . ' - ' . $feeHead;
                $st->execute([$institutionId, $academicYearId, $classId, $planName, $amount, $dueOn]);
                $saved++;
            }
            $this->audit($pdo, $request, 'finance', 'fee_category_matrix_saved', 'erp_fee_plans', (string) $classId, ['entries' => $saved]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->json($response, ['data' => $this->financePayload($pdo), 'saved' => $saved], 201);
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

    public function createExpense(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_role');
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return $this->json($response, ['error' => 'Only accountant or admin can record expenses'], 403);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $head = trim((string) ($body['expenseHead'] ?? ''));
        $vendor = trim((string) ($body['vendor'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);
        $mode = trim((string) ($body['paymentMode'] ?? 'Cash')) ?: 'Cash';
        $reference = trim((string) ($body['reference'] ?? '')) ?: null;
        $billNo = trim((string) ($body['billNo'] ?? '')) ?: null;
        $notes = trim((string) ($body['notes'] ?? '')) ?: null;
        $spentOn = trim((string) ($body['spentOn'] ?? '')) ?: date('Y-m-d\TH:i');
        if ($head === '' || $vendor === '' || $amount <= 0) {
            return $this->json($response, ['error' => 'Expense head, paid to/vendor and amount are required'], 422);
        }

        $pdo = $this->db->pdo();
        $this->ensureFinanceSchema($pdo);
        $voucherNo = 'EXP-' . date('Y') . '-' . str_pad((string) ((int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_expenses') + 1), 4, '0', STR_PAD_LEFT);
        $spentAt = str_replace('T', ' ', $spentOn);
        $st = $pdo->prepare(
            'INSERT INTO erp_expenses (voucher_no, expense_head, vendor, amount, payment_mode, reference_no, bill_no, spent_on, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $voucherNo,
            $head,
            $vendor,
            $amount,
            $mode,
            $reference,
            $billNo,
            $spentAt,
            $notes,
            (int) ($request->getAttribute('user_id') ?? 0) ?: null,
        ]);
        $this->audit($pdo, $request, 'finance', 'expense_recorded', 'erp_expenses', $voucherNo, ['head' => $head, 'vendor' => $vendor, 'amount' => $amount]);

        return $this->json($response, [
            'data' => $this->financePayload($pdo),
            'expense' => [
                'id' => (int) $pdo->lastInsertId(),
                'voucher_no' => $voucherNo,
                'expense_head' => $head,
                'vendor' => $vendor,
                'amount' => $amount,
                'payment_mode' => $mode,
                'reference_no' => $reference,
                'bill_no' => $billNo,
                'spent_on' => $spentAt,
                'notes' => $notes,
            ],
        ], 201);
    }

    private function compressDocumentImage(string $bytes): array
    {
        if (!function_exists('imagecreatefromstring')) {
            if (strlen($bytes) > self::ADMISSION_DOCUMENT_TARGET_BYTES) {
                throw new \RuntimeException('Image compression is not available on this server.');
            }
            return ['bytes' => $bytes];
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            throw new \RuntimeException('Invalid image document.');
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $maxWidth = 1400;
        do {
            $scale = min(1.0, $maxWidth / max($width, 1));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            for ($quality = 82; $quality >= 42; $quality -= 8) {
                ob_start();
                imagejpeg($canvas, null, $quality);
                $out = (string) ob_get_clean();
                if (strlen($out) <= self::ADMISSION_DOCUMENT_TARGET_BYTES || $quality <= 42) {
                    if (strlen($out) <= self::ADMISSION_DOCUMENT_TARGET_BYTES || $maxWidth <= 900) {
                        imagedestroy($canvas);
                        imagedestroy($source);
                        return ['bytes' => $out];
                    }
                    break;
                }
            }
            imagedestroy($canvas);
            $maxWidth -= 180;
        } while ($maxWidth >= 760);
        imagedestroy($source);
        throw new \RuntimeException('Unable to compress image under 300 KB. Please crop the document and try again.');
    }

    private function admissionDocumentNames(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT name FROM erp_saved_records WHERE module = 'Document master' AND status = 'Active' ORDER BY created_at DESC")->fetchAll();
        return array_values(array_unique(array_filter(array_map(static fn (array $row): string => trim((string) ($row['name'] ?? '')), $rows)))) ?: ['SSC', 'HSC', 'TC/LC', 'ADHAR', 'Passport photo'];
    }

    private function linkedStudentApplication(PDO $pdo, ServerRequestInterface $request): array|false
    {
        $email = trim((string) $request->getAttribute('user_email'));
        $digits = preg_replace('/\D+/', '', $email) ?: '';
        if ($digits !== '') {
            $st = $pdo->prepare('SELECT id, details_json FROM erp_admission_applications WHERE REPLACE(REPLACE(REPLACE(COALESCE(aadhar_no, JSON_UNQUOTE(JSON_EXTRACT(details_json, "$.\"Aadhar No\""))), " ", ""), "-", ""), ".", "") = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$digits]);
            $app = $st->fetch();
            if ($app) {
                return $app;
            }
        }
        if ($email !== '') {
            $st = $pdo->prepare('SELECT id, details_json FROM erp_admission_applications WHERE email = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$email]);
            return $st->fetch();
        }
        return false;
    }

    private function studentBrief(PDO $pdo, int $studentId): array|false
    {
        $st = $pdo->prepare(
            "SELECT s.id, s.institution_id, s.class_id, s.section_id, s.admission_no,
                    TRIM(CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, ''))) AS name,
                    s.phone, s.email, s.status, c.name AS class_name, sec.name AS section_name,
                    COALESCE((
                        SELECT JSON_UNQUOTE(JSON_EXTRACT(a.details_json, '$.\"Category\"'))
                        FROM erp_admission_applications a
                        WHERE a.target_class_id = s.class_id
                          AND ((a.phone IS NOT NULL AND a.phone <> '' AND a.phone = s.phone) OR (a.email IS NOT NULL AND a.email <> '' AND a.email = s.email))
                        ORDER BY a.id DESC
                        LIMIT 1
                    ), 'OPEN') AS category
             FROM erp_students s
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_sections sec ON sec.id = s.section_id
             WHERE s.id = ?
             LIMIT 1"
        );
        $st->execute([$studentId]);
        return $st->fetch();
    }

    private function latestScholarshipRecords(PDO $pdo): array
    {
        $this->ensureSavedRecordsSchema($pdo);
        $rows = $pdo->query(
            "SELECT r.id, r.status, r.payload_json, r.created_at, r.user_id, u.display_name, u.email
             FROM erp_saved_records r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.module IN ('Scholarship form', 'Scholarship receipt')
             ORDER BY r.created_at DESC"
        )->fetchAll();
        $latest = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            $studentId = (int) ($payload['studentId'] ?? 0);
            if ($studentId <= 0) {
                continue;
            }
            $type = (string) ($payload['type'] ?? (str_contains((string) $row['id'], 'SCHPAY') ? 'receipt' : 'form'));
            if (!isset($latest[$studentId])) {
                $latest[$studentId] = ['form' => null, 'receipt' => null, 'receipts' => []];
            }
            if ($type === 'receipt') {
                $audit = ['createdAt' => $row['created_at'], 'updatedByUserId' => (int) ($row['user_id'] ?? 0), 'updatedByName' => (string) ($row['display_name'] ?? ''), 'updatedByEmail' => (string) ($row['email'] ?? '')];
                $latest[$studentId]['receipt'] ??= $payload + $audit;
                $latest[$studentId]['receipts'][] = $payload + $audit;
            } elseif ($latest[$studentId]['form'] === null) {
                $latest[$studentId]['form'] = $payload + ['createdAt' => $row['created_at'], 'recordStatus' => $row['status'], 'updatedByUserId' => (int) ($row['user_id'] ?? 0), 'updatedByName' => (string) ($row['display_name'] ?? ''), 'updatedByEmail' => (string) ($row['email'] ?? '')];
            }
        }
        return $latest;
    }

    private function scholarshipStatusForStudent(PDO $pdo, int $studentId): ?array
    {
        $records = $this->latestScholarshipRecords($pdo);
        return $records[$studentId] ?? null;
    }

    private function scholarshipPayload(PDO $pdo): array
    {
        $this->ensureSavedRecordsSchema($pdo);
        $this->ensureFinanceSchema($pdo);
        $records = $this->latestScholarshipRecords($pdo);
        $students = $pdo->query(
            "SELECT s.id, s.institution_id, s.class_id, s.section_id, s.admission_no,
                    TRIM(CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, ''))) AS name,
                    s.phone, s.email, s.status, c.name AS class_name, sec.name AS section_name,
                    COALESCE((
                        SELECT JSON_UNQUOTE(JSON_EXTRACT(a.details_json, '$.\"Category\"'))
                        FROM erp_admission_applications a
                        WHERE a.target_class_id = s.class_id
                          AND ((a.phone IS NOT NULL AND a.phone <> '' AND a.phone = s.phone) OR (a.email IS NOT NULL AND a.email <> '' AND a.email = s.email))
                        ORDER BY a.id DESC
                        LIMIT 1
                    ), 'OPEN') AS category
             FROM erp_students s
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_sections sec ON sec.id = s.section_id
             ORDER BY c.level_order, sec.name, s.first_name, s.last_name"
        )->fetchAll();
        $plans = $pdo->query("SELECT class_id, name, amount FROM erp_fee_plans WHERE LOWER(name) LIKE '%scholarship%' ORDER BY class_id, name")->fetchAll();
        $planByClass = [];
        foreach ($plans as $plan) {
            $classId = (int) ($plan['class_id'] ?? 0);
            $planByClass[$classId][] = $plan;
        }
        $rows = array_map(function (array $student) use ($records, $planByClass, $pdo): array {
            $studentId = (int) $student['id'];
            $record = $records[$studentId] ?? ['form' => null, 'receipt' => null, 'receipts' => []];
            $form = $record['form'] ?? null;
            $receipt = $record['receipt'] ?? null;
            $activeYear = $this->activeAcademicYearMeta($pdo, (int) ($student['institution_id'] ?? 0));
            $studentCategory = strtoupper(trim((string) ($student['category'] ?? 'OPEN')));
            $classPlans = $planByClass[(int) $student['class_id']] ?? [];
            $configured = null;
            foreach ($classPlans as $plan) {
                $planName = strtoupper((string) ($plan['name'] ?? ''));
                if ($studentCategory !== '' && str_contains($planName, ' - ' . $studentCategory)) {
                    $configured = $plan;
                    break;
                }
            }
            $configured ??= $classPlans[0] ?? null;
            return [
                'studentId' => $studentId,
                'studentName' => $student['name'],
                'admissionNo' => $student['admission_no'],
                'classId' => (int) $student['class_id'],
                'className' => $student['class_name'],
                'sectionName' => $student['section_name'],
                'category' => $studentCategory,
                'phone' => $student['phone'],
                'email' => $student['email'],
                'formStatus' => (string) ($form['status'] ?? 'Not submitted'),
                'scheme' => (string) ($form['scheme'] ?? 'Government scholarship'),
                'formNumber' => (string) ($form['formNumber'] ?? ''),
                'academicYear' => (string) ($form['academicYear'] ?? $receipt['academicYear'] ?? $activeYear['academicYear'] ?? ''),
                'academicYearId' => (int) ($form['academicYearId'] ?? $receipt['academicYearId'] ?? $activeYear['academicYearId'] ?? 0),
                'verifiedAt' => (string) ($form['verifiedAt'] ?? ''),
                'verifiedByRole' => (string) ($form['verifiedByRole'] ?? ''),
                'formUpdatedAt' => (string) ($form['createdAt'] ?? ''),
                'formUpdatedByUserId' => (int) ($form['updatedByUserId'] ?? 0),
                'formUpdatedBy' => (string) ($form['updatedByName'] ?? ''),
                'formUpdatedByEmail' => (string) ($form['updatedByEmail'] ?? ''),
                'note' => (string) ($form['note'] ?? ''),
                'configuredAmount' => (float) ($configured['amount'] ?? 0),
                'configuredHead' => (string) ($configured['name'] ?? ''),
                'receivedAmount' => array_reduce($record['receipts'] ?? [], static fn (float $sum, array $row): float => $sum + (float) ($row['amount'] ?? 0), 0.0),
                'lastReceiptNo' => (string) ($receipt['receiptNo'] ?? ''),
                'lastReceivedAt' => (string) ($receipt['receivedAt'] ?? ''),
                'receiptUpdatedByUserId' => (int) ($receipt['updatedByUserId'] ?? 0),
                'receiptUpdatedBy' => (string) ($receipt['updatedByName'] ?? ''),
                'receiptUpdatedByEmail' => (string) ($receipt['updatedByEmail'] ?? ''),
            ];
        }, $students);
        $submitted = array_values(array_filter($rows, static fn (array $row): bool => strtolower((string) $row['formStatus']) !== 'not submitted'));
        return [
            'rows' => $rows,
            'summary' => [
                'students' => count($rows),
                'submitted' => count($submitted),
                'pending' => count($rows) - count($submitted),
                'received' => array_reduce($rows, static fn (float $sum, array $row): float => $sum + (float) $row['receivedAmount'], 0.0),
            ],
        ];
    }

    private function saveScholarshipLedgerRecord(PDO $pdo, ServerRequestInterface $request, int $studentId, string $scheme, float $amount, string $receiptNo, ?string $reference): void
    {
        $this->ensureSavedRecordsSchema($pdo);
        $student = $this->studentBrief($pdo, $studentId);
        if (!$student) {
            return;
        }
        $payload = [
            'type' => 'receipt',
            'studentId' => $studentId,
            'studentName' => $student['name'],
            'admissionNo' => $student['admission_no'],
            'classId' => (int) $student['class_id'],
            'className' => $student['class_name'],
            'sectionName' => $student['section_name'],
            'scheme' => $scheme,
            'amount' => $amount,
            'receiptNo' => $receiptNo,
            'reference' => $reference,
            'receivedAt' => date('Y-m-d H:i:s'),
        ] + $this->activeAcademicYearMeta($pdo, (int) $student['institution_id']);
        $id = 'SCHPAY-' . $studentId . '-' . date('Ymd-His');
        $st = $pdo->prepare('INSERT INTO erp_saved_records (id, user_id, module, name, code, status, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $userId = $request->getAttribute('user_id');
        $st->execute([$id, $userId !== null ? (int) $userId : null, 'Scholarship receipt', (string) $student['name'], 'SCHPAY-' . $studentId, 'Received', json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    private function financePayload(PDO $pdo): array
    {
        $this->ensureFinanceSchema($pdo);
        $classes = $pdo->query('SELECT id, name FROM erp_classes ORDER BY level_order, name')->fetchAll();
        $students = $pdo->query(
            "SELECT s.id, s.admission_no, CONCAT(s.first_name, ' ', s.last_name) AS name,
                    s.class_id, s.section_id, c.name AS class_name, sec.name AS section_name,
                    JSON_UNQUOTE(JSON_EXTRACT(a.details_json, '$.fee_group')) AS fee_group
             FROM erp_students s
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_sections sec ON sec.id = s.section_id
             LEFT JOIN erp_admission_applications a ON a.id = s.application_id
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
                    f.name AS fee_plan, JSON_UNQUOTE(JSON_EXTRACT(a.details_json, '$.fee_group')) AS fee_group
             FROM erp_fee_invoices i
             JOIN erp_students s ON s.id = i.student_id
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_fee_plans f ON f.id = i.fee_plan_id
             LEFT JOIN erp_admission_applications a ON a.id = s.application_id
             ORDER BY i.due_on, i.id"
        )->fetchAll();
        $payments = $pdo->query(
            "SELECT p.receipt_no, COALESCE(p.fee_head, f.name) AS fee_head, p.amount, p.method, p.paid_at, p.reference_no, i.invoice_no,
                    s.id AS student_id, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.first_name, s.last_name, s.admission_no, s.date_of_birth, s.phone, s.email,
                    c.name AS class_name, sec.name AS section_name, a.created_at AS admission_date, a.details_json AS admission_details_json,
                    (i.amount - i.discount_amount) AS fee_head_total,
                    i.paid_amount AS fee_head_paid,
                    (i.amount - i.discount_amount - i.paid_amount) AS balance_after,
                    (
                        SELECT COALESCE(SUM(ii.amount - ii.discount_amount - ii.paid_amount), 0)
                        FROM erp_fee_invoices ii
                        WHERE ii.student_id = s.id
                    ) AS total_balance_after,
                    di.document_no, di.document_type, di.issue_status AS document_issue_status, di.original_printed_at, di.issued_at AS document_issued_at
             FROM erp_fee_payments p
             JOIN erp_fee_invoices i ON i.id = p.invoice_id
             JOIN erp_students s ON s.id = i.student_id
             JOIN erp_classes c ON c.id = s.class_id
             JOIN erp_sections sec ON sec.id = s.section_id
             LEFT JOIN erp_admission_applications a ON a.id = s.application_id
             JOIN erp_fee_plans f ON f.id = i.fee_plan_id
             LEFT JOIN erp_document_issues di
                ON CAST(di.receipt_no AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci =
                   CAST(p.receipt_no AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
             ORDER BY p.paid_at DESC"
        )->fetchAll();
        $expenses = $pdo->query(
            "SELECT id, voucher_no, expense_head, vendor, amount, payment_mode, reference_no, bill_no, spent_on, notes, created_at
             FROM erp_expenses
             ORDER BY spent_on DESC, id DESC"
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
            'scholarships' => $this->scholarshipPayload($pdo),
            'expenses' => $expenses,
            'invoices' => $invoices,
            'payments' => $payments,
        ];
    }

    private function studentPortalSubjects(PDO $pdo, array $details, int $studentId): array
    {
        $subjects = [];
        $saved = is_array($details['subjects'] ?? null) ? $details['subjects'] : [];
        $byCode = [];
        foreach ($saved as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $code = trim((string) ($subject['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $semesters = [];
            if (is_array($subject['semesters'] ?? null)) {
                $semesters = array_values(array_filter(array_map('intval', $subject['semesters'])));
            }
            $semester = (int) ($subject['semester'] ?? $subject['semester_no'] ?? 0);
            if ($semester > 0) {
                $semesters[] = $semester;
            }
            $byCode[$code] = [
                'code' => $code,
                'name' => (string) ($subject['name'] ?? $code),
                'category' => (string) ($subject['category'] ?? $subject['subject_type'] ?? 'Subject'),
                'semesters' => array_values(array_unique(array_filter($semesters))),
            ];
        }

        if ($byCode !== []) {
            $lookup = $pdo->prepare('SELECT code, name, subject_type FROM erp_subjects WHERE code = ? LIMIT 1');
            foreach ($byCode as $code => $subject) {
                $lookup->execute([$code]);
                $row = $lookup->fetch();
                if ($row) {
                    $subject['name'] = $subject['name'] !== $code ? $subject['name'] : (string) $row['name'];
                    $subject['category'] = (string) ($row['subject_type'] ?? $subject['category']);
                }
                foreach (($subject['semesters'] ?: [1]) as $semesterNo) {
                    $subjects[] = [
                        'semester' => (int) $semesterNo,
                        'code' => $subject['code'],
                        'name' => $subject['name'],
                        'category' => $subject['category'],
                    ];
                }
            }
        }

        if ($subjects === [] && $studentId > 0) {
            $st = $pdo->prepare(
                'SELECT ss.semester_no AS semester, sub.code, sub.name, sub.subject_type AS category
                 FROM erp_students s
                 JOIN erp_section_subjects ss ON ss.section_id = s.section_id
                 JOIN erp_subjects sub ON sub.id = ss.subject_id
                 WHERE s.id = ?
                 ORDER BY ss.semester_no, ss.is_mandatory DESC, sub.name'
            );
            $st->execute([$studentId]);
            $subjects = $st->fetchAll();
        }

        usort($subjects, static fn (array $a, array $b): int => ((int) $a['semester'] <=> (int) $b['semester']) ?: strcmp((string) $a['name'], (string) $b['name']));
        return $subjects;
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
        $st = $pdo->query("SHOW COLUMNS FROM erp_students LIKE 'application_id'");
        if (!$st->fetch()) {
            $pdo->exec('ALTER TABLE erp_students ADD COLUMN application_id INT UNSIGNED NULL AFTER section_id');
            $pdo->exec('CREATE INDEX idx_erp_students_application ON erp_students (application_id)');
        }
        $st = $pdo->query("SHOW COLUMNS FROM erp_fee_payments LIKE 'fee_head'");
        if (!$st->fetch()) {
            $pdo->exec('ALTER TABLE erp_fee_payments ADD COLUMN fee_head VARCHAR(160) NULL AFTER receipt_no');
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_no VARCHAR(40) NOT NULL UNIQUE,
                expense_head VARCHAR(160) NOT NULL,
                vendor VARCHAR(180) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                payment_mode VARCHAR(40) NOT NULL DEFAULT "Cash",
                reference_no VARCHAR(120) NULL,
                bill_no VARCHAR(120) NULL,
                spent_on DATETIME NOT NULL,
                notes TEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_erp_expenses_spent_on (spent_on),
                INDEX idx_erp_expenses_head (expense_head)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_document_issues (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                receipt_no VARCHAR(60) NOT NULL,
                document_type VARCHAR(120) NOT NULL,
                document_no VARCHAR(80) NOT NULL UNIQUE,
                fee_head VARCHAR(160) NULL,
                issue_status VARCHAR(80) NOT NULL DEFAULT "Payment collected",
                issued_at DATETIME NOT NULL,
                original_printed_at DATETIME NULL,
                duplicate_print_count INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_erp_document_receipt_type (receipt_no, document_type),
                INDEX idx_erp_document_student (student_id),
                INDEX idx_erp_document_type (document_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function ensureHrSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_departments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                code VARCHAR(40) NULL UNIQUE,
                hod_user_id INT NULL,
                notes TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_erp_departments_hod (hod_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_employees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_no VARCHAR(50) NOT NULL UNIQUE,
                user_id INT NULL,
                department_id INT NOT NULL,
                name VARCHAR(190) NOT NULL,
                gender VARCHAR(30) NULL,
                phone VARCHAR(40) NULL,
                email VARCHAR(190) NULL,
                designation VARCHAR(120) NOT NULL,
                employee_type VARCHAR(80) NOT NULL DEFAULT "Permanent aided",
                staff_type VARCHAR(80) NOT NULL DEFAULT "Teaching",
                joining_date DATE NULL,
                status VARCHAR(40) NOT NULL DEFAULT "Active",
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_erp_employees_department (department_id),
                INDEX idx_erp_employees_user (user_id),
                INDEX idx_erp_employees_type (employee_type, staff_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_leave_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(140) NOT NULL,
                code VARCHAR(40) NULL UNIQUE,
                applicable_to VARCHAR(100) NOT NULL DEFAULT "All employees",
                annual_quota DECIMAL(6,2) NOT NULL DEFAULT 0,
                is_paid TINYINT(1) NOT NULL DEFAULT 1,
                carry_forward TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_employee_leave_balances (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                category_id INT NOT NULL,
                leave_year INT NOT NULL,
                total_days DECIMAL(6,2) NOT NULL DEFAULT 0,
                taken_days DECIMAL(6,2) NOT NULL DEFAULT 0,
                pending_days DECIMAL(6,2) NOT NULL DEFAULT 0,
                balance_days DECIMAL(6,2) NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_employee_leave_year (employee_id, category_id, leave_year),
                INDEX idx_employee_leave_balance_employee (employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_leave_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                applicant_type VARCHAR(30) NOT NULL DEFAULT "employee",
                employee_id INT NULL,
                student_id INT NULL,
                category_id INT NULL,
                from_date DATE NOT NULL,
                to_date DATE NOT NULL,
                days DECIMAL(6,2) NOT NULL DEFAULT 1,
                reason TEXT NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "Pending",
                review_note TEXT NULL,
                approver_user_id INT NULL,
                approved_at DATETIME NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_erp_leave_employee (employee_id),
                INDEX idx_erp_leave_student (student_id),
                INDEX idx_erp_leave_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_salary_structures (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL UNIQUE,
                basic DECIMAL(12,2) NOT NULL DEFAULT 0,
                pf DECIMAL(12,2) NOT NULL DEFAULT 0,
                esi DECIMAL(12,2) NOT NULL DEFAULT 0,
                loan DECIMAL(12,2) NOT NULL DEFAULT 0,
                ta DECIMAL(12,2) NOT NULL DEFAULT 0,
                da DECIMAL(12,2) NOT NULL DEFAULT 0,
                hra DECIMAL(12,2) NOT NULL DEFAULT 0,
                other_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
                other_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
                gross DECIMAL(12,2) NOT NULL DEFAULT 0,
                net DECIMAL(12,2) NOT NULL DEFAULT 0,
                effective_from DATE NOT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_salary_slips (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slip_no VARCHAR(60) NOT NULL UNIQUE,
                employee_id INT NOT NULL,
                salary_month VARCHAR(7) NOT NULL,
                basic DECIMAL(12,2) NOT NULL DEFAULT 0,
                allowance_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                deduction_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                gross DECIMAL(12,2) NOT NULL DEFAULT 0,
                net DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT "Generated",
                generated_by INT NULL,
                generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_salary_employee_month (employee_id, salary_month),
                INDEX idx_salary_month (salary_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $count = (int) $this->scalarInt($pdo, 'SELECT COUNT(*) FROM erp_leave_categories');
        if ($count === 0) {
            $seed = [
                ['Casual Leave', 'CL', 'Permanent aided employees', 12, 1, 0],
                ['Medical Leave', 'ML', 'Permanent aided employees', 10, 1, 0],
                ['Earned Leave', 'EL', 'Permanent aided employees', 15, 1, 1],
                ['Leave Without Pay', 'LWP', 'All employees', 0, 0, 0],
                ['Student Leave', 'STU', 'Students', 0, 0, 0],
            ];
            $st = $pdo->prepare('INSERT INTO erp_leave_categories (name, code, applicable_to, annual_quota, is_paid, carry_forward) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($seed as $row) {
                $st->execute($row);
            }
        }
    }

    private function ensureStudentPromotionSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_student_promotions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                institution_id INT UNSIGNED NOT NULL,
                student_id INT UNSIGNED NOT NULL,
                from_class_id INT UNSIGNED NULL,
                from_section_id INT UNSIGNED NULL,
                to_class_id INT UNSIGNED NOT NULL,
                to_section_id INT UNSIGNED NOT NULL,
                effective_date DATE NOT NULL,
                note TEXT NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_erp_student_promotions_student (student_id),
                INDEX idx_erp_student_promotions_institution (institution_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function salaryFieldsFromBody(array $body): array
    {
        $keys = ['pf', 'esi', 'loan', 'ta', 'da', 'hra', 'other_allowance', 'other_deduction'];
        $out = [];
        foreach ($keys as $key) {
            $camel = preg_replace_callback('/_([a-z])/', fn ($m) => strtoupper($m[1]), $key);
            $out[$key] = (float) ($body[$key] ?? $body[$camel] ?? 0);
        }
        return $out;
    }

    private function employeeIdForUser(PDO $pdo, int $userId): ?int
    {
        if ($userId <= 0) return null;
        $st = $pdo->prepare('SELECT id FROM erp_employees WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $id = $st->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function upsertLeaveBalance(PDO $pdo, int $employeeId, int $categoryId, int $year, float $takenDays): void
    {
        $st = $pdo->prepare('SELECT annual_quota FROM erp_leave_categories WHERE id = ? LIMIT 1');
        $st->execute([$categoryId]);
        $quota = (float) ($st->fetchColumn() ?: 0);
        $pdo->prepare('INSERT INTO erp_employee_leave_balances (employee_id, category_id, leave_year, total_days, taken_days, balance_days)
            VALUES (?, ?, ?, ?, ?, GREATEST(? - ?, 0))
            ON DUPLICATE KEY UPDATE total_days = IF(total_days = 0, VALUES(total_days), total_days),
                taken_days = taken_days + VALUES(taken_days),
                balance_days = GREATEST(total_days - taken_days, 0)')
            ->execute([$employeeId, $categoryId, $year, $quota, $takenDays, $quota, $takenDays]);
    }

    private function hrPayload(PDO $pdo, ServerRequestInterface $request): array
    {
        $this->ensureHrSchema($pdo);
        $role = (string) $request->getAttribute('user_role');
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $departmentFilter = '';
        $params = [];
        if ($role === 'hod') {
            $departmentFilter = ' WHERE d.hod_user_id = ?';
            $params[] = $userId;
        }
        $departmentsSt = $pdo->prepare("SELECT d.*, u.display_name AS hod_name, u.email AS hod_email FROM erp_departments d LEFT JOIN users u ON u.id = d.hod_user_id{$departmentFilter} ORDER BY d.name");
        $departmentsSt->execute($params);
        $departments = $departmentsSt->fetchAll();

        $employeeWhere = $role === 'hod' ? 'WHERE d.hod_user_id = ?' : '';
        $employeesSt = $pdo->prepare("SELECT e.*, d.name AS department_name, d.hod_user_id, s.net AS salary_net, s.gross AS salary_gross
            FROM erp_employees e
            JOIN erp_departments d ON d.id = e.department_id
            LEFT JOIN erp_salary_structures s ON s.employee_id = e.id
            {$employeeWhere}
            ORDER BY d.name, e.name");
        $employeesSt->execute($role === 'hod' ? [$userId] : []);
        $employees = $employeesSt->fetchAll();

        $leaveSt = $pdo->prepare(
            "SELECT l.*, lc.name AS category_name, e.name AS employee_name, e.employee_no, d.name AS department_name,
                    CONCAT(COALESCE(stu.first_name, ''), ' ', COALESCE(stu.last_name, '')) AS student_name,
                    u.display_name AS created_by_name, approver.display_name AS approver_name
             FROM erp_leave_applications l
             LEFT JOIN erp_leave_categories lc ON lc.id = l.category_id
             LEFT JOIN erp_employees e ON e.id = l.employee_id
             LEFT JOIN erp_departments d ON d.id = e.department_id
             LEFT JOIN erp_students stu ON stu.id = l.student_id
             LEFT JOIN users u ON u.id = l.created_by
             LEFT JOIN users approver ON approver.id = l.approver_user_id
             " . ($role === 'hod' ? 'WHERE d.hod_user_id = ?' : '') . '
             ORDER BY l.created_at DESC LIMIT 300'
        );
        $leaveSt->execute($role === 'hod' ? [$userId] : []);

        $balances = $pdo->query(
            'SELECT b.*, e.name AS employee_name, lc.name AS category_name
             FROM erp_employee_leave_balances b
             JOIN erp_employees e ON e.id = b.employee_id
             JOIN erp_leave_categories lc ON lc.id = b.category_id
             ORDER BY b.leave_year DESC, e.name, lc.name'
        )->fetchAll();
        $salaryStructures = $pdo->query(
            'SELECT s.*, e.name AS employee_name, e.employee_no, d.name AS department_name
             FROM erp_salary_structures s
             JOIN erp_employees e ON e.id = s.employee_id
             JOIN erp_departments d ON d.id = e.department_id
             ORDER BY e.name'
        )->fetchAll();
        $salarySlips = $pdo->query(
            'SELECT ss.*, e.name AS employee_name, e.employee_no, d.name AS department_name
             FROM erp_salary_slips ss
             JOIN erp_employees e ON e.id = ss.employee_id
             JOIN erp_departments d ON d.id = e.department_id
             ORDER BY ss.salary_month DESC, e.name LIMIT 300'
        )->fetchAll();
        $leaveCategories = $pdo->query('SELECT * FROM erp_leave_categories WHERE is_active = 1 ORDER BY name')->fetchAll();
        $users = $pdo->query('SELECT id, display_name, email, role FROM users ORDER BY display_name, email')->fetchAll();
        $summary = [
            'departments' => count($departments),
            'employees' => count($employees),
            'pendingLeaves' => count(array_filter($leaveSt->fetchAll(), fn ($row) => (string) $row['status'] === 'Pending')),
        ];
        $leaveSt->execute($role === 'hod' ? [$userId] : []);
        $leaveRows = $leaveSt->fetchAll();
        $summary['pendingLeaves'] = count(array_filter($leaveRows, fn ($row) => (string) $row['status'] === 'Pending'));
        $summary['salaryMonthTotal'] = array_sum(array_map(fn ($row) => (float) $row['net'], array_filter($salarySlips, fn ($row) => (string) $row['salary_month'] === date('Y-m'))));
        return [
            'summary' => $summary,
            'departments' => $departments,
            'employees' => $employees,
            'leaveCategories' => $leaveCategories,
            'leaveApplications' => $leaveRows,
            'leaveBalances' => $balances,
            'salaryStructures' => $salaryStructures,
            'salarySlips' => $salarySlips,
            'users' => $users,
            'role' => $role,
        ];
    }

    private function ensureAdmissionOtpSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_email_settings (
                institution_id INT UNSIGNED NOT NULL PRIMARY KEY,
                smtp_host VARCHAR(190) NOT NULL DEFAULT "smtp.gmail.com",
                smtp_port INT NOT NULL DEFAULT 587,
                smtp_encryption VARCHAR(20) NOT NULL DEFAULT "tls",
                smtp_username VARCHAR(190) NOT NULL,
                smtp_password VARCHAR(255) NOT NULL,
                from_email VARCHAR(190) NOT NULL,
                from_name VARCHAR(190) NOT NULL DEFAULT "ROSELAND SCHOOL Admissions",
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS erp_admission_edit_otps (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                admission_id INT NOT NULL,
                email VARCHAR(190) NOT NULL,
                otp_hash VARCHAR(255) NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                verified_at DATETIME NULL,
                edit_token_hash VARCHAR(255) NULL,
                edit_token_expires_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_erp_admission_edit_otps_admission (admission_id, created_at),
                INDEX idx_erp_admission_edit_otps_token (admission_id, edit_token_expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function admissionIdFromReference(string $reference): int
    {
        if (preg_match('/(?:APP|LBP|RSL)-\d{4}-(\d+)/i', $reference, $m)) {
            return (int) ltrim($m[1], '0');
        }
        return (int) (preg_replace('/\D+/', '', $reference) ?: 0);
    }

    private function verifyAdmissionEditToken(PDO $pdo, int $admissionId, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $this->ensureAdmissionOtpSchema($pdo);
        $st = $pdo->prepare(
            'SELECT id, edit_token_hash FROM erp_admission_edit_otps
             WHERE admission_id = ? AND verified_at IS NOT NULL AND edit_token_hash IS NOT NULL AND edit_token_expires_at >= NOW()
             ORDER BY id DESC LIMIT 5'
        );
        $st->execute([$admissionId]);
        foreach ($st->fetchAll() as $row) {
            if (password_verify($token, (string) $row['edit_token_hash'])) {
                return true;
            }
        }
        return false;
    }

    private function loadEmailSettings(PDO $pdo): ?array
    {
        $this->ensureAdmissionOtpSchema($pdo);
        $institutionId = $this->activeInstitutionId($pdo);
        $st = $pdo->prepare('SELECT * FROM erp_email_settings WHERE institution_id = ? LIMIT 1');
        $st->execute([$institutionId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private function emailSettingsResponse(array $settings): array
    {
        return [
            'smtpHost' => (string) ($settings['smtp_host'] ?? 'smtp.gmail.com'),
            'smtpPort' => (int) ($settings['smtp_port'] ?? 587),
            'smtpEncryption' => (string) ($settings['smtp_encryption'] ?? 'tls'),
            'smtpUsername' => (string) ($settings['smtp_username'] ?? ''),
            'fromEmail' => (string) ($settings['from_email'] ?? ''),
            'fromName' => (string) ($settings['from_name'] ?? 'ROSELAND SCHOOL Admissions'),
            'isEnabled' => (int) ($settings['is_enabled'] ?? 0) === 1,
            'passwordConfigured' => trim((string) ($settings['smtp_password'] ?? '')) !== '',
            'updatedAt' => (string) ($settings['updated_at'] ?? ''),
        ];
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $prefix = substr($name, 0, 2);
        return $prefix . str_repeat('*', max(2, strlen($name) - 2)) . '@' . $domain;
    }

    private function sendConfiguredEmail(array $settings, string $to, string $subject, string $body): void
    {
        $host = (string) ($settings['smtp_host'] ?? 'smtp.gmail.com');
        $port = (int) ($settings['smtp_port'] ?? 587);
        $encryption = strtolower((string) ($settings['smtp_encryption'] ?? 'tls'));
        $username = (string) ($settings['smtp_username'] ?? '');
        $password = (string) ($settings['smtp_password'] ?? '');
        $fromEmail = (string) ($settings['from_email'] ?? $username);
        $fromName = (string) ($settings['from_name'] ?? 'ROSELAND SCHOOL Admissions');
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new \RuntimeException($errstr ?: 'SMTP connection failed');
        }
        stream_set_timeout($socket, 12);
        $read = static function () use ($socket): string {
            $response = '';
            while (($line = fgets($socket, 515)) !== false) {
                $response .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };
        $write = static function (string $command) use ($socket, $read): string {
            fwrite($socket, $command . "\r\n");
            return $read();
        };
        $expect = static function (string $response, array $codes): void {
            $code = substr($response, 0, 3);
            if (!in_array($code, $codes, true)) {
                throw new \RuntimeException(trim($response) ?: 'SMTP command failed');
            }
        };
        $expect($read(), ['220']);
        $expect($write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), ['250']);
        if ($encryption === 'tls') {
            $expect($write('STARTTLS'), ['220']);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('SMTP TLS negotiation failed');
            }
            $expect($write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), ['250']);
        }
        $expect($write('AUTH LOGIN'), ['334']);
        $expect($write(base64_encode($username)), ['334']);
        $expect($write(base64_encode($password)), ['235']);
        $expect($write('MAIL FROM:<' . $fromEmail . '>'), ['250']);
        $expect($write('RCPT TO:<' . $to . '>'), ['250', '251']);
        $expect($write('DATA'), ['354']);
        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body) . "\r\n.";
        $expect($write($payload), ['250']);
        $write('QUIT');
        fclose($socket);
    }

    private function isTransferCertificateHead(string $feeHead): bool
    {
        $head = strtolower($feeHead);
        return str_contains($head, 'transfer certificate') || preg_match('/\btc\b/', $head) === 1 || str_contains($head, 'nirgam') || str_contains($head, 'leaving certificate');
    }

    private function ensureTransferCertificateIssue(PDO $pdo, int $studentId, string $receiptNo, string $feeHead, ?string $issuedAt = null): array
    {
        $this->ensureFinanceSchema($pdo);
        $existing = $pdo->prepare('SELECT * FROM erp_document_issues WHERE receipt_no = ? AND document_type = ? LIMIT 1');
        $existing->execute([$receiptNo, 'Transfer Certificate']);
        $row = $existing->fetch();
        if ($row) {
            return $this->documentIssueRow($row);
        }
        $sequence = (int) $this->scalarInt($pdo, "SELECT COUNT(*) FROM erp_document_issues WHERE document_type = 'Transfer Certificate'") + 1;
        $documentNo = 'TC-' . date('Y') . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        $createdAt = $issuedAt ?: date('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO erp_document_issues (student_id, receipt_no, document_type, document_no, fee_head, issue_status, issued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$studentId, $receiptNo, 'Transfer Certificate', $documentNo, $feeHead, 'Payment collected', $createdAt]);
        return [
            'student_id' => $studentId,
            'receipt_no' => $receiptNo,
            'document_type' => 'Transfer Certificate',
            'document_no' => $documentNo,
            'fee_head' => $feeHead,
            'status' => 'Payment collected',
            'issued_at' => $createdAt,
            'original_printed_at' => null,
            'duplicate_print_count' => 0,
        ];
    }

    private function documentIssueRow(array $row): array
    {
        return [
            'student_id' => (int) ($row['student_id'] ?? 0),
            'receipt_no' => (string) ($row['receipt_no'] ?? ''),
            'document_type' => (string) ($row['document_type'] ?? ''),
            'document_no' => (string) ($row['document_no'] ?? ''),
            'fee_head' => (string) ($row['fee_head'] ?? ''),
            'status' => (string) ($row['issue_status'] ?? 'Payment collected'),
            'issued_at' => (string) ($row['issued_at'] ?? ''),
            'original_printed_at' => $row['original_printed_at'] ?? null,
            'duplicate_print_count' => (int) ($row['duplicate_print_count'] ?? 0),
        ];
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

    private function ensureCourseSubjectPlanSchema(PDO $pdo): void
    {
        $exists = $pdo->query("SHOW TABLES LIKE 'erp_course_subject_plans'")->fetchColumn();
        if ($exists) {
            return;
        }
        $pdo->exec(
            "CREATE TABLE erp_course_subject_plans (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              institution_id INT UNSIGNED NOT NULL,
              course_name VARCHAR(120) NOT NULL,
              row_no INT UNSIGNED NOT NULL DEFAULT 1,
              semester_no TINYINT UNSIGNED NOT NULL,
              subject_id INT UNSIGNED NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_erp_course_subject_plan (institution_id, course_name, row_no, semester_no),
              KEY idx_erp_course_subject_plan_course (course_name, semester_no),
              CONSTRAINT fk_erp_course_subject_plan_inst FOREIGN KEY (institution_id) REFERENCES erp_institutions(id) ON DELETE CASCADE,
              CONSTRAINT fk_erp_course_subject_plan_subject FOREIGN KEY (subject_id) REFERENCES erp_subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureSubjectSelectionLimitSchema(PDO $pdo): void
    {
        $exists = $pdo->query("SHOW TABLES LIKE 'erp_section_subject_limits'")->fetchColumn();
        if ($exists) {
            return;
        }
        $pdo->exec(
            "CREATE TABLE erp_section_subject_limits (
              section_id INT UNSIGNED NOT NULL,
              semester_no TINYINT UNSIGNED NOT NULL,
              max_subjects TINYINT UNSIGNED NOT NULL DEFAULT 8,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (section_id, semester_no),
              CONSTRAINT fk_erp_section_subject_limit_section FOREIGN KEY (section_id) REFERENCES erp_sections(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureDynamicSubjectPlanSchema(PDO $pdo): void
    {
        $this->ensureCourseSubjectPlanSchema($pdo);
        $this->ensureSubjectSelectionLimitSchema($pdo);
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS erp_subject_group_templates (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              institution_id INT UNSIGNED NOT NULL,
              group_key VARCHAR(80) NOT NULL,
              group_name VARCHAR(160) NOT NULL,
              selection_type VARCHAR(40) NOT NULL DEFAULT 'select_one',
              min_select TINYINT UNSIGNED NOT NULL DEFAULT 0,
              max_select TINYINT UNSIGNED NOT NULL DEFAULT 1,
              sort_order INT UNSIGNED NOT NULL DEFAULT 1,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_erp_subject_group_template (institution_id, group_key),
              CONSTRAINT fk_erp_subject_group_template_inst FOREIGN KEY (institution_id) REFERENCES erp_institutions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS erp_course_year_subject_groups (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              institution_id INT UNSIGNED NOT NULL,
              course_name VARCHAR(120) NOT NULL,
              year_name VARCHAR(160) NOT NULL,
              group_key VARCHAR(80) NOT NULL,
              group_name VARCHAR(160) NOT NULL,
              parent_group_key VARCHAR(80) NULL,
              sort_order INT UNSIGNED NOT NULL DEFAULT 1,
              selection_type VARCHAR(40) NOT NULL DEFAULT 'select_one',
              min_select TINYINT UNSIGNED NOT NULL DEFAULT 0,
              max_select TINYINT UNSIGNED NOT NULL DEFAULT 1,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              locked_after_admission TINYINT(1) NOT NULL DEFAULT 0,
              is_major_source TINYINT(1) NOT NULL DEFAULT 0,
              creates_major_lock TINYINT(1) NOT NULL DEFAULT 0,
              allow_student_choice TINYINT(1) NOT NULL DEFAULT 1,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_erp_course_year_subject_group (institution_id, course_name, year_name, group_key),
              KEY idx_erp_course_year_subject_group_lookup (course_name, year_name, sort_order),
              CONSTRAINT fk_erp_course_year_subject_group_inst FOREIGN KEY (institution_id) REFERENCES erp_institutions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS erp_course_year_group_subjects (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              group_id INT UNSIGNED NOT NULL,
              semester_no TINYINT UNSIGNED NOT NULL,
              subject_id INT UNSIGNED NOT NULL,
              subject_family_key VARCHAR(120) NULL,
              sort_order INT UNSIGNED NOT NULL DEFAULT 1,
              is_default TINYINT(1) NOT NULL DEFAULT 0,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_erp_course_year_group_subject (group_id, semester_no, subject_id),
              KEY idx_erp_course_year_group_subject_sem (group_id, semester_no, sort_order),
              CONSTRAINT fk_erp_course_year_group_subject_group FOREIGN KEY (group_id) REFERENCES erp_course_year_subject_groups(id) ON DELETE CASCADE,
              CONSTRAINT fk_erp_course_year_group_subject_subject FOREIGN KEY (subject_id) REFERENCES erp_subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS erp_subject_papers (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              institution_id INT UNSIGNED NOT NULL,
              course_name VARCHAR(120) NOT NULL,
              year_name VARCHAR(160) NOT NULL,
              semester_no TINYINT UNSIGNED NOT NULL,
              subject_id INT UNSIGNED NOT NULL,
              paper_code VARCHAR(80) NOT NULL,
              paper_name VARCHAR(220) NOT NULL,
              paper_type VARCHAR(40) NOT NULL DEFAULT 'Theory',
              sort_order INT UNSIGNED NOT NULL DEFAULT 1,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_erp_subject_paper (institution_id, course_name, year_name, semester_no, subject_id, paper_code),
              KEY idx_erp_subject_paper_lookup (course_name, year_name, semester_no, subject_id),
              CONSTRAINT fk_erp_subject_paper_inst FOREIGN KEY (institution_id) REFERENCES erp_institutions(id) ON DELETE CASCADE,
              CONSTRAINT fk_erp_subject_paper_subject FOREIGN KEY (subject_id) REFERENCES erp_subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS erp_student_subject_selections (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              student_id INT UNSIGNED NULL,
              application_id INT UNSIGNED NULL,
              course_name VARCHAR(120) NOT NULL,
              year_name VARCHAR(160) NOT NULL,
              semester_no TINYINT UNSIGNED NOT NULL,
              group_key VARCHAR(80) NOT NULL,
              group_name VARCHAR(160) NOT NULL,
              subject_id INT UNSIGNED NOT NULL,
              selection_sequence INT UNSIGNED NOT NULL DEFAULT 1,
              is_major TINYINT(1) NOT NULL DEFAULT 0,
              is_dropped TINYINT(1) NOT NULL DEFAULT 0,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_erp_student_subject_selection_student (student_id, application_id),
              CONSTRAINT fk_erp_student_subject_selection_subject FOREIGN KEY (subject_id) REFERENCES erp_subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $institutionId = $this->activeInstitutionId($pdo);
        $defaultGroups = [
            ['compulsory', 'Compulsory', 'auto_all', 0, 99, 1],
            ['second_language', 'Second Language', 'select_one', 1, 1, 2],
            ['non_faculty', 'Open Elective Subject', 'select_one', 1, 1, 3],
            ['core_group', 'Core Group', 'select_exact', 3, 3, 4],
            ['major_subject', 'Major Subject', 'select_one', 1, 1, 5],
            ['optional_group', 'Optional Group', 'select_one', 0, 1, 6],
        ];
        $template = $pdo->prepare(
            'INSERT INTO erp_subject_group_templates (institution_id, group_key, group_name, selection_type, min_select, max_select, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE group_name = VALUES(group_name), selection_type = VALUES(selection_type), min_select = VALUES(min_select), max_select = VALUES(max_select), sort_order = VALUES(sort_order)'
        );
        foreach ($defaultGroups as $group) {
            $template->execute([$institutionId, $group[0], $group[1], $group[2], $group[3], $group[4], $group[5]]);
        }
    }

    private function subjectSetupPayload(PDO $pdo): array
    {
        $this->ensureDynamicSubjectPlanSchema($pdo);
        return [
            'courseYearGroups' => $this->normalizeCourseYearGroups($pdo->query('SELECT * FROM erp_course_year_subject_groups ORDER BY course_name, year_name, sort_order, id')->fetchAll()),
            'courseYearGroupSubjects' => $pdo->query(
                'SELECT gs.group_id, g.course_name, g.year_name, g.group_key, gs.semester_no, gs.subject_id, gs.subject_family_key, gs.sort_order, gs.is_default, sub.code, sub.name, sub.subject_type
                 FROM erp_course_year_group_subjects gs
                 JOIN erp_course_year_subject_groups g ON g.id = gs.group_id
                 JOIN erp_subjects sub ON sub.id = gs.subject_id
                 ORDER BY g.course_name, g.year_name, g.group_key, gs.semester_no, gs.sort_order, sub.name'
            )->fetchAll(),
            'subjectPapers' => $pdo->query(
                'SELECT p.id, p.course_name, p.year_name, p.semester_no, p.subject_id, sub.code AS subject_code, sub.name AS subject_name, p.paper_code, p.paper_name, p.paper_type, p.sort_order
                 FROM erp_subject_papers p
                 JOIN erp_subjects sub ON sub.id = p.subject_id
                 ORDER BY p.course_name, p.year_name, p.semester_no, sub.name, p.sort_order, p.paper_code'
            )->fetchAll(),
        ];
    }

    private function subjectIdByCode(PDO $pdo, int $institutionId, string $subjectCode): int
    {
        $st = $pdo->prepare('SELECT id FROM erp_subjects WHERE institution_id = ? AND code = ? LIMIT 1');
        $st->execute([$institutionId, $subjectCode]);
        return (int) $st->fetchColumn();
    }

    private function normalizeCourseYearGroups(array $groups): array
    {
        return array_map(static function (array $group): array {
            if (($group['group_key'] ?? '') === 'non_faculty') {
                $group['group_name'] = 'Open Elective Subject';
            }
            return $group;
        }, $groups);
    }

    private function subjectGroupKey(string $name): string
    {
        $key = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $name) ?? $name, '_'));
        return $key !== '' ? substr($key, 0, 80) : 'group_' . date('His');
    }

    private function courseYearGroupId(PDO $pdo, int $institutionId, string $course, string $yearName, string $groupKey): int
    {
        $st = $pdo->prepare('SELECT id FROM erp_course_year_subject_groups WHERE institution_id = ? AND course_name = ? AND year_name = ? AND group_key = ? LIMIT 1');
        $st->execute([$institutionId, $course, $yearName, $groupKey]);
        return (int) $st->fetchColumn();
    }

    private function classCourseMapping(PDO $pdo, int $classId): array
    {
        $rows = $pdo->query("SELECT payload_json FROM erp_saved_records WHERE module = 'Class course mapping' ORDER BY created_at DESC")->fetchAll();
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
            if ((int) ($payload['classId'] ?? 0) === $classId) {
                return ['course' => (string) ($payload['course'] ?? '')];
            }
        }
        return ['course' => ''];
    }

    private function courseYearSubjectSelectionPayload(PDO $pdo, string $course, string $yearName): array
    {
        if ($course === '' || $yearName === '') {
            return [];
        }
        $groups = $pdo->prepare('SELECT * FROM erp_course_year_subject_groups WHERE course_name = ? AND year_name = ? AND is_active = 1 ORDER BY sort_order, id');
        $groups->execute([$course, $yearName]);
        $groupRows = $this->normalizeCourseYearGroups($groups->fetchAll());
        if (!$groupRows) {
            return [];
        }
        $subjects = $pdo->prepare(
            'SELECT gs.group_id, gs.semester_no, gs.subject_family_key, gs.sort_order, gs.is_default, sub.code, sub.name, sub.subject_type
             FROM erp_course_year_group_subjects gs
             JOIN erp_subjects sub ON sub.id = gs.subject_id
             WHERE gs.group_id = ?
             ORDER BY gs.semester_no, gs.sort_order, sub.name'
        );
        return array_map(function (array $group) use ($subjects): array {
            $subjects->execute([(int) $group['id']]);
            $rows = $subjects->fetchAll();
            return $group + ['subjects' => $rows];
        }, $groupRows);
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
        $activityTimeline = [];
        try {
            $activityTimeline = $this->admissionActivityTimeline($this->db->pdo(), (int) $row['id']);
        } catch (\Throwable) {
            $activityTimeline = [];
        }
        $referenceYear = date('Y', strtotime((string) ($row['created_at'] ?? 'now')) ?: time());
        return [
            'id' => 'RSL-' . $referenceYear . '-' . str_pad((string) $row['id'], 4, '0', STR_PAD_LEFT),
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
            'activityTimeline' => $activityTimeline,
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

    private function publicAdmissionResponse(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare(
            'SELECT a.id, a.applicant_name, a.aadhar_no, a.details_json, a.stage, a.created_at, c.name AS class_name
             FROM erp_admission_applications a
             LEFT JOIN erp_classes c ON c.id = a.target_class_id
             WHERE a.id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new \RuntimeException('Admission row missing after write');
        }
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        return $this->publicAdmissionResponseFromRow($row, $details);
    }

    private function publicAdmissionResponseFromRow(array $row, array $details): array
    {
        $stage = (string) ($row['stage'] ?? 'application');
        $details['Aadhar No'] = (string) ($row['aadhar_no'] ?? ($details['Aadhar No'] ?? ''));
        $statusText = strtolower(trim((string) ($details['admission_status'] ?? '')));
        $submitted = (bool) ($details['public_website_admission_form'] ?? false)
            || str_contains($statusText, 'submitted from website')
            || str_contains($statusText, 'pending document verification')
            || str_contains($statusText, 'document verified')
            || str_contains($statusText, 'active admission')
            || in_array($stage, ['offer', 'fee_paid', 'enrolled'], true);
        $activityTimeline = $this->admissionActivityTimeline($this->db->pdo(), (int) $row['id']);
        $referenceYear = date('Y', strtotime((string) ($row['created_at'] ?? 'now')) ?: time());
        return [
            'numericId' => (int) $row['id'],
            'id' => 'RSL-' . $referenceYear . '-' . str_pad((string) $row['id'], 4, '0', STR_PAD_LEFT),
            'name' => (string) ($row['applicant_name'] ?? ''),
            'className' => (string) ($row['class_name'] ?? ($details['Admission Class'] ?? '')),
            'status' => !$submitted && str_contains($statusText, 'in progress') ? 'Admission form in progress' : ($stage === 'application' ? 'Pending document verification' : $this->displayStage($stage)),
            'submitted' => $submitted,
            'details' => $details,
            'activityTimeline' => $activityTimeline,
        ];
    }

    private function admissionActivityTimeline(PDO $pdo, int $admissionId): array
    {
        $st = $pdo->prepare(
            "SELECT l.id, l.user_id, l.action, l.metadata_json, l.created_at,
                    u.display_name, u.email
             FROM erp_audit_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.entity_type = 'erp_admission_applications'
               AND l.entity_id = ?
               AND (
                    l.module = 'admissions'
                    OR (l.module = 'student' AND l.action = 'photo_uploaded')
               )
             ORDER BY l.created_at ASC, l.id ASC"
        );
        $st->execute([(string) $admissionId]);
        $actionLabels = [
            'created' => 'Admission enquiry created',
            'public_enquiry_created' => 'Admission enquiry created',
            'public_admission_form_submitted' => 'Admission form submitted',
            'public_admission_form_updated' => 'Admission form edited',
            'admission_form_updated' => 'Admission form edited',
            'documents_saved' => 'Documents verified',
            'document_uploaded' => 'Document uploaded',
            'photo_uploaded' => 'Student photograph uploaded',
            'converted' => 'Admission confirmed and fees paid',
            'advanced' => 'Admission stage updated',
        ];
        $importantActions = array_keys($actionLabels);
        $rows = [];
        foreach ($st->fetchAll() as $row) {
            $action = (string) ($row['action'] ?? '');
            if (!in_array($action, $importantActions, true)) {
                continue;
            }
            $meta = json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [];
            $name = trim((string) ($row['display_name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $role = trim((string) ($meta['userRole'] ?? ''));
            if ($name === '' && $email !== '') {
                $name = $email;
            }
            if ($name === '') {
                $name = in_array($action, ['public_admission_form_submitted', 'public_admission_form_updated'], true) ? 'Student / Website' : 'System / Website';
            }
            $note = '';
            if ($action === 'documents_saved') {
                $pending = is_array($meta['pending'] ?? null) ? count($meta['pending']) : 0;
                $note = $pending > 0 ? $pending . ' document(s) pending' : 'All required documents verified';
            } elseif ($action === 'converted') {
                $amount = (float) ($meta['payment_amount'] ?? 0);
                $note = $amount > 0 ? 'Admission payment: INR ' . number_format($amount, 2) : 'Admission activated';
            } elseif ($action === 'admission_form_updated') {
                $count = (int) ($meta['changedCount'] ?? 0);
                $note = $count > 0 ? $count . ' field(s) updated' : 'Form reviewed and saved';
            } elseif ($action === 'document_uploaded') {
                $note = (string) ($meta['document'] ?? '');
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'action' => $action,
                'label' => $actionLabels[$action],
                'createdAt' => (string) ($row['created_at'] ?? ''),
                'userId' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'userName' => $name,
                'userEmail' => $email,
                'userRole' => $role,
                'note' => $note,
            ];
        }

        $preferredOrder = [
            'public_admission_form_submitted',
            'admission_form_updated',
            'public_admission_form_updated',
            'documents_saved',
            'converted',
        ];
        $hasSubmission = array_filter($rows, static fn (array $row): bool => in_array($row['action'], ['public_admission_form_submitted', 'admission_form_updated', 'public_admission_form_updated'], true));
        if (!$hasSubmission) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['action'] !== 'created' && $row['action'] !== 'public_enquiry_created'));
        }
        usort($rows, static function (array $a, array $b) use ($preferredOrder): int {
            $aIndex = array_search($a['action'], $preferredOrder, true);
            $bIndex = array_search($b['action'], $preferredOrder, true);
            $aIndex = $aIndex === false ? 99 : $aIndex;
            $bIndex = $bIndex === false ? 99 : $bIndex;
            return $aIndex <=> $bIndex ?: strcmp((string) $a['createdAt'], (string) $b['createdAt']);
        });
        return array_values($rows);
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

    private function normalizeImportRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[$this->importKey((string) $key)] = is_scalar($value) ? trim((string) $value) : '';
        }
        return $normalized;
    }

    private function importKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = str_replace(['/', '-', '.', "'", '"', '(', ')'], ' ', $key);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
        return trim($key, '_');
    }

    private function importValue(array $row, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $key = $this->importKey((string) $alias);
            if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
                return (string) $row[$key];
            }
        }
        return '';
    }

    private function resolveClassIdStrict(PDO $pdo, int $institutionId, string $name): int
    {
        if (trim($name) === '') {
            return 0;
        }
        $name = $this->normalizeClassName($name);
        $st = $pdo->prepare('SELECT id FROM erp_classes WHERE institution_id = ? AND (name = ? OR REPLACE(REPLACE(LOWER(name), ".", ""), " ", "") = REPLACE(REPLACE(LOWER(?), ".", ""), " ", "")) LIMIT 1');
        $st->execute([$institutionId, $name, $name]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    private function resolveSectionIdStrict(PDO $pdo, int $classId, string $name): int
    {
        if ($classId <= 0) {
            return 0;
        }
        if ($name === '') {
            return $this->defaultSectionIdForClass($pdo, $classId);
        }
        $st = $pdo->prepare('SELECT id FROM erp_sections WHERE class_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
        $st->execute([$classId, $name]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    private function classNameById(PDO $pdo, int $classId): string
    {
        $st = $pdo->prepare('SELECT name FROM erp_classes WHERE id = ? LIMIT 1');
        $st->execute([$classId]);
        return (string) ($st->fetchColumn() ?: '');
    }

    private function sectionNameById(PDO $pdo, int $sectionId): string
    {
        $st = $pdo->prepare('SELECT name FROM erp_sections WHERE id = ? LIMIT 1');
        $st->execute([$sectionId]);
        return (string) ($st->fetchColumn() ?: '');
    }

    private function detailsFromExistingStudentImportRow(array $row): array
    {
        $map = [
            'Admission To' => ['admission_to', 'course'],
            'ABC ID' => ['abc_id'],
            'Student Saral ID' => ['student_saral_id', 'saral_id'],
            'UDISE No' => ['udise_no'],
            'Mother Name' => ['mother_name'],
            'Gender' => ['gender'],
            'Blood Group' => ['blood_group'],
            'Place of Birth' => ['place_of_birth'],
            'Mother Tongue' => ['mother_tongue'],
            'Religion' => ['religion'],
            'Category' => ['category'],
            'Caste' => ['caste'],
            'Subcaste' => ['subcaste'],
            'Residential Address' => ['residential_address', 'address'],
            'Pin Code' => ['pin_code', 'pincode'],
            'Country' => ['country'],
            'State' => ['state'],
            'District' => ['district'],
            'Taluka' => ['taluka'],
            'Village Name' => ['village_name'],
            "Parent's/Guardian's Mobile Number" => ['parent_mobile', 'guardian_mobile'],
            'Nationality' => ['nationality'],
            'Domicile Of State' => ['domicile_state'],
            'SSC Seat No' => ['ssc_seat_no'],
            'SSC Month' => ['ssc_month'],
            'SSC Year' => ['ssc_year'],
            'SSC Board/College' => ['ssc_board_college', 'ssc_board'],
            'HSC / XIth Seat No' => ['hsc_xith_seat_no', 'hsc_seat_no'],
            'HSC / XIth Month' => ['hsc_xith_month', 'hsc_month'],
            'HSC / XIth Year' => ['hsc_xith_year', 'hsc_year'],
            'HSC / XIth Board/College' => ['hsc_xith_board_college', 'hsc_board'],
            'Qualification Type' => ['qualification_type'],
            'Name of Qualification' => ['name_of_qualification'],
            'College Name' => ['college_name'],
            'Qualification Status' => ['qualification_status'],
            'Board/University' => ['board_university'],
            'Out of Marks' => ['out_of_marks'],
            'Obtained Marks' => ['obtained_marks'],
            'Percentage' => ['percentage'],
            'Account No' => ['account_no'],
            'IFSC Code' => ['ifsc_code'],
            'Account Holder' => ['account_holder'],
            'Documents Available' => ['documents_available'],
            'Subjects' => ['subjects'],
        ];
        $details = [];
        foreach ($map as $label => $aliases) {
            $value = $this->importValue($row, $aliases);
            if ($value !== '') {
                $details[$label] = strtoupper($value);
            }
        }
        return $details;
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
        return $clean !== '' ? $clean : 'Class I';
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
