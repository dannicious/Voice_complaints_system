# Complaint System - New Form Implementation Guide

## Overview
The complaint system database schema has been updated to match the official **BISSU Complaint Form** structure. This guide covers the database changes and required PHP form updates.

---

## 📊 Database Schema Changes

### Old Complaints Table Fields (Removed/Changed):
```
- subject (simplified to: act_complained_of + narrative_report)
- description (split into multiple detailed fields)
- is_escalated (removed)
- visibility_status (removed)
```

### New Complaints Table Fields (Added):

#### Complainant Information
```sql
- complainant_name VARCHAR(100)          -- Full name of person filing complaint
- complainant_address TEXT                -- Residential address
- complainant_sex ENUM('male','female','other') -- Gender
- complainant_age INT(3)                 -- Age
- complainant_civil_status VARCHAR(50)   -- Single, married, widowed, etc.
- complainant_contact_details VARCHAR(255) -- Phone, email, etc.
```

#### Person Complained Of
```sql
- person_complained_of VARCHAR(100)      -- Name/title/office of person/entity being complained about
```

#### Incident Details
```sql
- date_of_incident DATE                  -- When did the incident occur
- place_of_incident VARCHAR(255)         -- Location of incident
- time_of_incident TIME                  -- Time of incident
- act_complained_of TEXT                 -- Specific act/behavior complained about
- narrative_report LONGTEXT              -- Detailed narrative report
```

#### Evidence & Outcome
```sql
- proof_of_complaint TEXT                -- Documents/evidence/witnesses (JSON or comma-separated)
- attachments VARCHAR(255)               -- Path to supporting documents
- desired_outcome TEXT                   -- What outcome does complainant expect
```

#### Legal Agreement
```sql
- terms_agreement_accepted TINYINT(1)    -- Has terms & conditions been accepted
- signature VARCHAR(255)                 -- Path to digital signature (if captured)
```

---

## 🔄 Database Migration Steps

### Option 1: Fresh Database (Recommended for Development)
```bash
1. Open phpMyAdmin
2. Drop the current 'voicedb' database
3. Import 'voicedb_v2_updated.sql'
4. Verify all tables are created correctly
```

### Option 2: Update Existing Database
```bash
1. Backup current database: voicedb
2. Run 'complaint_form_migration.sql' step by step
3. This will drop old complaints/complaint_reactions tables
4. Create new tables with updated structure
```

### Option 3: Safe Migration (Preserve Old Data)
```bash
1. Run: RENAME TABLE complaints TO complaints_archive;
2. Run the migration script without the DROP statements
3. Keep complaint_archive for historical reference
```

---

## 📝 PHP Form Updates Required

### File: `student/process_complaint.php`

**Old Form Fields to Remove:**
- `subject` (moved to: act_complained_of)
- `description` (split into: narrative_report)

**New Form Fields to Add:**
```php
// Complainant Information
$_POST['complainant_name']          // Pre-fill from student profile
$_POST['complainant_address']
$_POST['complainant_sex']           // From student profile
$_POST['complainant_age']           // Calculated from student profile
$_POST['complainant_civil_status']  // New field
$_POST['complainant_contact_details'] // Phone number

// Person Complained Of
$_POST['person_complained_of']      // New field - name/office of person being complained about

// Incident Details
$_POST['date_of_incident']          // Date picker
$_POST['place_of_incident']         // Dropdown or text field
$_POST['time_of_incident']          // Time picker
$_POST['act_complained_of']         // Text area (detailed)
$_POST['narrative_report']          // Large text area (full narrative)

// Evidence
$_POST['proof_of_complaint']        // Multiple file upload or text field
$_POST['attachments']               // Document attachments

// Outcome
$_POST['desired_outcome']           // Text area

// Agreement
$_POST['terms_agreement_accepted']  // Checkbox (must be checked)
// Signature handling if digital signature is implemented
```

**Updated SQL INSERT:**
```php
$ticket_no = 'VOX-C-' . date('Y-') . str_pad($complaint_id, 4, '0', STR_PAD_LEFT);

$query = "INSERT INTO complaints (
    ticket_no,
    student_id,
    complainant_name,
    complainant_address,
    complainant_sex,
    complainant_age,
    complainant_civil_status,
    complainant_contact_details,
    person_complained_of,
    date_of_incident,
    place_of_incident,
    time_of_incident,
    act_complained_of,
    narrative_report,
    college_id,
    category_id,
    proof_of_complaint,
    attachments,
    desired_outcome,
    terms_agreement_accepted,
    is_anonymous,
    status,
    created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())";
```

---

## 📋 Form HTML Template Update

### File: `student/student_complaints.php`

Replace the old form with:

```html
<form id="complaintForm" method="POST" action="process_complaint.php" enctype="multipart/form-data">
    
    <!-- COMPLAINANT INFORMATION SECTION -->
    <section class="form-section">
        <h3>Details of the Complainant</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="complainant_name">Name of Complainant: <span class="required">*</span></label>
                <input type="text" id="complainant_name" name="complainant_name" 
                       value="<?php echo htmlspecialchars($student_name); ?>" required>
            </div>
            <div class="form-group">
                <label for="complainant_sex">Sex: <span class="required">*</span></label>
                <select id="complainant_sex" name="complainant_sex" required>
                    <option value="">-- Select --</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label for="complainant_age">Age: <span class="required">*</span></label>
                <input type="number" id="complainant_age" name="complainant_age" 
                       min="16" max="100" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="complainant_address">Address: <span class="required">*</span></label>
                <textarea id="complainant_address" name="complainant_address" 
                          rows="3" required></textarea>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="complainant_civil_status">Civil Status: <span class="required">*</span></label>
                <select id="complainant_civil_status" name="complainant_civil_status" required>
                    <option value="">-- Select --</option>
                    <option value="single">Single</option>
                    <option value="married">Married</option>
                    <option value="widowed">Widowed</option>
                    <option value="separated">Separated</option>
                    <option value="divorced">Divorced</option>
                </select>
            </div>
            <div class="form-group">
                <label for="complainant_contact_details">Contact Details (Phone/Email): <span class="required">*</span></label>
                <input type="text" id="complainant_contact_details" name="complainant_contact_details" 
                       placeholder="09XX-XXX-XXXX or email@example.com" required>
            </div>
        </div>
    </section>
    
    <!-- COMPLAINT DETAILS SECTION -->
    <section class="form-section">
        <h3>Complaint Details</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="person_complained_of">Name of Person/Office Complained Of: <span class="required">*</span></label>
                <input type="text" id="person_complained_of" name="person_complained_of" 
                       placeholder="e.g., Dean John Doe, Registrar's Office" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="date_of_incident">Date of Incident: <span class="required">*</span></label>
                <input type="date" id="date_of_incident" name="date_of_incident" required>
            </div>
            <div class="form-group">
                <label for="time_of_incident">Time of Incident:</label>
                <input type="time" id="time_of_incident" name="time_of_incident">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="place_of_incident">Place of Incident: <span class="required">*</span></label>
                <input type="text" id="place_of_incident" name="place_of_incident" 
                       placeholder="e.g., Library, Classroom 201, Cafeteria" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="act_complained_of">Act/s Complained Of: <span class="required">*</span></label>
                <textarea id="act_complained_of" name="act_complained_of" 
                          rows="4" placeholder="Describe the specific act or behavior complained about" required></textarea>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="narrative_report">Narrative Report of Complaint: <span class="required">*</span></label>
                <textarea id="narrative_report" name="narrative_report" 
                          rows="6" placeholder="Provide a detailed account of the complaint" required></textarea>
            </div>
        </div>
    </section>
    
    <!-- PROOF OF COMPLAINT SECTION -->
    <section class="form-section">
        <h3>Proof of Complaint</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="proof_of_complaint">Documents/Evidence/Witnesses:</label>
                <textarea id="proof_of_complaint" name="proof_of_complaint" 
                          rows="3" placeholder="List any documents, evidence, or witnesses"></textarea>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="attachments">File Attachments:</label>
                <input type="file" id="attachments" name="attachments[]" 
                       multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt">
                <small>Accepted formats: PDF, DOC, DOCX, JPG, PNG, TXT</small>
            </div>
        </div>
    </section>
    
    <!-- DESIRED OUTCOME SECTION -->
    <section class="form-section">
        <h3>Complaint Outcome</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="desired_outcome">As a result of making this complaint, what outcome would you like to have/expect?: <span class="required">*</span></label>
                <textarea id="desired_outcome" name="desired_outcome" 
                          rows="4" placeholder="Describe the desired outcome or resolution" required></textarea>
            </div>
        </div>
    </section>
    
    <!-- TERMS & AGREEMENT SECTION -->
    <section class="form-section">
        <h3>Terms of Agreement</h3>
        
        <div class="form-row">
            <p>Upon filling-up this form, I bind myself to stand on the truth of this complaint as a COMPLAINANT/AGGRIEVED PARTY on behalf of the public and the institution for legal proceedings may be required as provided by the existing laws.</p>
            
            <div class="form-group checkbox">
                <input type="checkbox" id="terms_agreement_accepted" name="terms_agreement_accepted" 
                       value="1" required>
                <label for="terms_agreement_accepted">
                    I agree that the provided information asked herein will be used by the University for whatever legal purpose it may serve. <span class="required">*</span>
                </label>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="signature">Signature: <span class="required">*</span></label>
                <input type="text" id="signature" name="signature" 
                       placeholder="Signature over Printed Name" required>
            </div>
            <div class="form-group">
                <label for="signature_date">Date: <span class="required">*</span></label>
                <input type="date" id="signature_date" name="signature_date" 
                       value="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
    </section>
    
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Submit Complaint</button>
        <button type="reset" class="btn btn-secondary">Clear Form</button>
    </div>
</form>
```

---

## 🔍 Admin/Dean Display Updates

### File: `admin/admin_complaints_v2.php` and `dean/dean_complaints.php`

Update the complaint display to show:
```php
// Instead of just subject and description, display:
- Complainant Name: [name]
- Person Complained Of: [person_name]
- Date of Incident: [date]
- Place of Incident: [place]
- Act Complained Of: [act]
- Narrative Report: [narrative]
- Desired Outcome: [outcome]
- Proof Provided: [evidence]
```

---

## ✅ Implementation Checklist

- [ ] Backup current voicedb database
- [ ] Import new SQL schema (voicedb_v2_updated.sql)
- [ ] Update `student/process_complaint.php` with new fields
- [ ] Update `student/student_complaints.php` form HTML
- [ ] Update `admin/admin_complaints_v2.php` display logic
- [ ] Update `dean/dean_complaints.php` display logic
- [ ] Test complaint submission with all new fields
- [ ] Test admin/dean complaint view
- [ ] Test file attachments functionality
- [ ] Verify ticket generation works
- [ ] Test email notifications (if applicable)
- [ ] Verify date/time fields work correctly

---

## 📌 Field Mapping Reference

| FORM SECTION | FORM FIELD | DATABASE COLUMN |
|---|---|---|
| COMPLAINANT | Name | complainant_name |
| COMPLAINANT | Address | complainant_address |
| COMPLAINANT | Sex | complainant_sex |
| COMPLAINANT | Age | complainant_age |
| COMPLAINANT | Civil Status | complainant_civil_status |
| COMPLAINANT | Contact Details | complainant_contact_details |
| COMPLAINT DETAILS | Person Complained Of | person_complained_of |
| COMPLAINT DETAILS | Date of Incident | date_of_incident |
| COMPLAINT DETAILS | Place of Incident | place_of_incident |
| COMPLAINT DETAILS | Time | time_of_incident |
| COMPLAINT DETAILS | Act Complained Of | act_complained_of |
| COMPLAINT DETAILS | Narrative Report | narrative_report |
| PROOF | Documents/Evidence | proof_of_complaint |
| PROOF | Attachments | attachments |
| OUTCOME | Desired Outcome | desired_outcome |
| AGREEMENT | Terms Accepted | terms_agreement_accepted |
| SIGNATURE | Signature | signature |

---

## 🚀 Next Steps

1. **Review** the schema changes above
2. **Backup** your current database
3. **Import** the new schema
4. **Update** all PHP files listed
5. **Test** thoroughly before going live
6. **Monitor** for any issues

Need help with any specific file updates?
