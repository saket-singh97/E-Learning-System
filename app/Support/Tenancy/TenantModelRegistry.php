<?php

namespace App\Support\Tenancy;

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Assignments\Models\Certificate;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Comms\Models\ParentInviteCode;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Fees\Models\FeeCoupon;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Tenancy\Models\RoleAssignmentAudit;
use App\Models\User;
use RuntimeException;

class TenantModelRegistry
{
    /**
     * Every model using the BelongsToTenant trait must be listed here.
     * tests/Isolation/CrossTenantTest.php iterates this array to build the
     * cross-tenant isolation suite; a class missing from this list fails the
     * guard test in the same file.
     */
    public const MODELS = [
        RoleAssignmentAudit::class,
        Course::class,
        CourseSection::class,
        Lesson::class,
        VideoWatchEvent::class,
        BandwidthUsageDaily::class,
        LessonArtifact::class,
        LessonArtifactChunk::class,
        DoubtChatMessage::class,
        Batch::class,
        BatchSession::class,
        Enrolment::class,
        AttendanceRecord::class,
        Question::class,
        Quiz::class,
        QuizAttempt::class,
        QuizAnswer::class,
        FeeCoupon::class,
        FeeInvoice::class,
        FeePayment::class,
        ParentGuardianLink::class,
        ParentInviteCode::class,
        Assignment::class,
        AssignmentSubmission::class,
        Certificate::class,
    ];

    /**
     * Valid create() attributes for a registered model, used by
     * CrossTenantTest to seed rows generically. Not every tenant model has
     * the same shape, so this is a lookup rather than a shared literal.
     *
     * @return array<string, mixed>
     */
    public static function seedAttributes(string $modelClass): array
    {
        return match ($modelClass) {
            Course::class => ['title' => 'Row', 'slug' => 'row-'.uniqid()],
            CourseSection::class => ['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Row', 'position' => 0],
            Lesson::class => ['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0],
            RoleAssignmentAudit::class => [
                'actor_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'target_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'actor_role' => 'institute_admin',
                'attempted_role' => 'super_admin',
                'allowed' => false,
            ],
            VideoWatchEvent::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                'start_s' => 0,
                'end_s' => 10,
            ],
            BandwidthUsageDaily::class => ['date' => now()->toDateString(), 'bytes_served' => 0],
            LessonArtifact::class => [
                'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                'version' => 1,
                'source_asset_version' => 'v1',
            ],
            LessonArtifactChunk::class => [
                'lesson_artifact_id' => LessonArtifact::create([
                    'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                    'version' => 1,
                    'source_asset_version' => 'v1',
                ])->id,
                'chunk_idx' => 0,
                'start_s' => 0,
                'end_s' => 5,
                'text' => 'Row',
            ],
            DoubtChatMessage::class => [
                'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'question' => 'Row?',
                'answer' => 'Row.',
                'cited_chunk_idxs' => [0],
            ],
            Batch::class => [
                'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                'title' => 'Row',
                'slug' => 'row-'.uniqid(),
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addMonth()->toDateString(),
                'self_enrol_mode' => 'manual_only',
                'status' => 'draft',
            ],
            BatchSession::class => [
                'batch_id' => Batch::create([
                    'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                    'title' => 'Parent',
                    'slug' => 'parent-'.uniqid(),
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addMonth()->toDateString(),
                    'self_enrol_mode' => 'manual_only',
                    'status' => 'draft',
                ])->id,
                'lesson_id' => Lesson::create([
                    'course_section_id' => CourseSection::create([
                        'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                        'title' => 'Parent',
                        'position' => 0,
                    ])->id,
                    'title' => 'Row',
                    'type' => 'live_session',
                    'position' => 0,
                ])->id,
                'starts_at' => now()->addWeek(),
                'duration_minutes' => 60,
                'position' => 0,
                'live_provider' => 'external_link',
                'provider_meeting_ref' => 'https://meet.example.com/row',
            ],
            Enrolment::class => [
                'batch_id' => Batch::create([
                    'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                    'title' => 'Parent',
                    'slug' => 'parent-'.uniqid(),
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addMonth()->toDateString(),
                    'self_enrol_mode' => 'manual_only',
                    'status' => 'draft',
                ])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'active',
                'source' => 'manual_add',
                'requested_at' => now(),
            ],
            AttendanceRecord::class => [
                'attendable_type' => 'lesson',
                'attendable_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'present',
                'source' => 'manual',
                'recorded_at' => now(),
            ],
            Question::class => [
                'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                'type' => 'mcq',
                'stem' => 'Row?',
                'options' => ['A', 'B'],
                'correct_answer' => 'A',
                'difficulty' => 'easy',
                'status' => 'draft',
                'source' => 'manual',
            ],
            Quiz::class => [
                'lesson_id' => Lesson::create([
                    'course_section_id' => CourseSection::create([
                        'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                        'title' => 'Parent',
                        'position' => 0,
                    ])->id,
                    'title' => 'Row',
                    'type' => 'quiz',
                    'position' => 0,
                ])->id,
                'title' => 'Row Quiz',
                'blueprint' => [['difficulty' => 'easy', 'count' => 1]],
                'time_limit_minutes' => 30,
                'max_attempts' => 3,
            ],
            QuizAttempt::class => [
                'quiz_id' => Quiz::create([
                    'lesson_id' => Lesson::create([
                        'course_section_id' => CourseSection::create([
                            'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                            'title' => 'Parent',
                            'position' => 0,
                        ])->id,
                        'title' => 'Parent',
                        'type' => 'quiz',
                        'position' => 0,
                    ])->id,
                    'title' => 'Parent Quiz',
                    'blueprint' => [],
                    'time_limit_minutes' => 30,
                    'max_attempts' => 3,
                ])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'question_ids' => [1],
                'status' => 'in_progress',
                'started_at' => now(),
                'expires_at' => now()->addMinutes(30),
                'max_score' => 10,
            ],
            QuizAnswer::class => [
                'quiz_attempt_id' => QuizAttempt::create([
                    'quiz_id' => Quiz::create([
                        'lesson_id' => Lesson::create([
                            'course_section_id' => CourseSection::create([
                                'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                                'title' => 'Parent',
                                'position' => 0,
                            ])->id,
                            'title' => 'Parent',
                            'type' => 'quiz',
                            'position' => 0,
                        ])->id,
                        'title' => 'Parent Quiz',
                        'blueprint' => [],
                        'time_limit_minutes' => 30,
                        'max_attempts' => 3,
                    ])->id,
                    'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'question_ids' => [1],
                    'status' => 'in_progress',
                    'started_at' => now(),
                    'expires_at' => now()->addMinutes(30),
                    'max_score' => 10,
                ])->id,
                'question_id' => Question::create([
                    'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                    'type' => 'mcq',
                    'stem' => 'Row?',
                    'options' => ['A', 'B'],
                    'correct_answer' => 'A',
                    'difficulty' => 'easy',
                    'status' => 'draft',
                    'source' => 'manual',
                ])->id,
                'response' => ['answer' => 'A'],
                'status' => 'pending_review',
            ],
            FeeCoupon::class => [
                'code' => 'ROW'.strtoupper(substr(uniqid(), -6)),
                'discount_type' => 'percentage',
                'discount_value' => 10,
            ],
            FeeInvoice::class => [
                'batch_id' => Batch::create([
                    'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                    'title' => 'Parent',
                    'slug' => 'parent-'.uniqid(),
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addMonth()->toDateString(),
                    'self_enrol_mode' => 'manual_only',
                    'status' => 'published',
                    'is_paid' => true,
                    'price' => 100,
                ])->id,
                'enrolment_id' => Enrolment::create([
                    'batch_id' => Batch::create([
                        'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                        'title' => 'Parent',
                        'slug' => 'parent-'.uniqid(),
                        'starts_on' => now()->toDateString(),
                        'ends_on' => now()->addMonth()->toDateString(),
                        'self_enrol_mode' => 'manual_only',
                        'status' => 'published',
                        'is_paid' => true,
                        'price' => 100,
                    ])->id,
                    'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'status' => 'pending_payment',
                    'source' => 'self_enrol_direct',
                    'requested_at' => now(),
                ])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'sequence_number' => 1,
                'invoice_number' => 'INV-ROW-'.uniqid(),
                'description' => 'Row',
                'amount' => 100,
                'gst_amount' => 18,
                'total_amount' => 118,
                'due_date' => now()->toDateString(),
            ],
            FeePayment::class => [
                'fee_invoice_id' => FeeInvoice::create([
                    'batch_id' => Batch::create([
                        'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                        'title' => 'Parent',
                        'slug' => 'parent-'.uniqid(),
                        'starts_on' => now()->toDateString(),
                        'ends_on' => now()->addMonth()->toDateString(),
                        'self_enrol_mode' => 'manual_only',
                        'status' => 'published',
                        'is_paid' => true,
                        'price' => 100,
                    ])->id,
                    'enrolment_id' => Enrolment::create([
                        'batch_id' => Batch::create([
                            'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                            'title' => 'Parent',
                            'slug' => 'parent-'.uniqid(),
                            'starts_on' => now()->toDateString(),
                            'ends_on' => now()->addMonth()->toDateString(),
                            'self_enrol_mode' => 'manual_only',
                            'status' => 'published',
                            'is_paid' => true,
                            'price' => 100,
                        ])->id,
                        'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                        'status' => 'pending_payment',
                        'source' => 'self_enrol_direct',
                        'requested_at' => now(),
                    ])->id,
                    'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'sequence_number' => 1,
                    'invoice_number' => 'INV-ROW-'.uniqid(),
                    'description' => 'Row',
                    'amount' => 100,
                    'gst_amount' => 18,
                    'total_amount' => 118,
                    'due_date' => now()->toDateString(),
                ])->id,
                'provider_order_id' => 'order_row_'.uniqid(),
                'idempotency_key' => 'idem_'.uniqid(),
                'amount' => 118,
                'status' => 'created',
            ],
            ParentGuardianLink::class => [
                'parent_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'student_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'relationship' => 'father',
            ],
            ParentInviteCode::class => [
                'student_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'code' => strtoupper(substr(uniqid(), -8)),
                'expires_at' => now()->addDays(7),
                'created_by' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
            ],
            Assignment::class => [
                'lesson_id' => Lesson::create([
                    'course_section_id' => CourseSection::create([
                        'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                        'title' => 'Parent',
                        'position' => 0,
                    ])->id,
                    'title' => 'Row',
                    'type' => 'assignment',
                    'position' => 0,
                ])->id,
                'title' => 'Row Assignment',
                'due_at' => now()->addWeek(),
                'max_marks' => 100,
            ],
            AssignmentSubmission::class => [
                'assignment_id' => Assignment::create([
                    'lesson_id' => Lesson::create([
                        'course_section_id' => CourseSection::create([
                            'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                            'title' => 'Parent',
                            'position' => 0,
                        ])->id,
                        'title' => 'Parent',
                        'type' => 'assignment',
                        'position' => 0,
                    ])->id,
                    'title' => 'Parent Assignment',
                    'due_at' => now()->addWeek(),
                    'max_marks' => 100,
                ])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'version_number' => 1,
                'files' => [['disk_path' => 'row.pdf', 'original_name' => 'row.pdf', 'size_kb' => 100]],
                'submitted_at' => now(),
            ],
            Certificate::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id,
                'verification_id' => 'CERT-'.uniqid(),
                'final_score' => 75,
                'pdf_disk_path' => 'certificates/row.pdf',
                'issued_at' => now(),
            ],
            default => throw new RuntimeException("No seed attributes registered for [{$modelClass}]."),
        };
    }

    /**
     * A single-column change used by CrossTenantTest to prove a
     * cross-tenant `update()` affects 0 rows.
     *
     * @return array<string, mixed>
     */
    public static function updateAttributes(string $modelClass): array
    {
        return match ($modelClass) {
            Course::class => ['title' => 'tampered'],
            CourseSection::class => ['title' => 'tampered'],
            Lesson::class => ['title' => 'tampered'],
            RoleAssignmentAudit::class => ['attempted_role' => 'tampered'],
            VideoWatchEvent::class => ['end_s' => 999],
            BandwidthUsageDaily::class => ['bytes_served' => 999],
            LessonArtifact::class => ['source_asset_version' => 'tampered'],
            LessonArtifactChunk::class => ['text' => 'tampered'],
            DoubtChatMessage::class => ['answer' => 'tampered'],
            Batch::class => ['title' => 'tampered'],
            BatchSession::class => ['provider_meeting_ref' => 'https://tampered.example.com'],
            Enrolment::class => ['status' => 'tampered'],
            AttendanceRecord::class => ['status' => 'tampered'],
            Question::class => ['stem' => 'tampered'],
            Quiz::class => ['title' => 'tampered'],
            QuizAttempt::class => ['status' => 'tampered'],
            QuizAnswer::class => ['status' => 'tampered'],
            FeeCoupon::class => ['code' => 'TAMPERED'],
            FeeInvoice::class => ['status' => 'tampered'],
            FeePayment::class => ['status' => 'tampered'],
            ParentGuardianLink::class => ['relationship' => 'tampered'],
            ParentInviteCode::class => ['redeemed_at' => now()],
            Assignment::class => ['title' => 'tampered'],
            AssignmentSubmission::class => ['feedback' => 'tampered'],
            Certificate::class => ['pdf_disk_path' => 'tampered.pdf'],
            default => throw new RuntimeException("No update attributes registered for [{$modelClass}]."),
        };
    }
}
