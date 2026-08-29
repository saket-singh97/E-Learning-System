<?php

namespace App\Support\Tenancy;

use App\Domains\AI\Models\CourseOutlineDraft;
use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Models\LessonRevisionNote;
use App\Domains\AI\Models\PhotoDoubtQuery;
use App\Domains\Api\Models\ApiKey;
use App\Domains\Api\Models\WebhookDelivery;
use App\Domains\Api\Models\WebhookEndpoint;
use App\Domains\Analytics\Models\Badge;
use App\Domains\Analytics\Models\GamificationProfile;
use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Domains\Analytics\Models\StudentBadge;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\QuizProctoringEvent;
use App\Domains\Assessments\Models\OmrSheetScan;
use App\Domains\Assessments\Models\OmrTemplate;
use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Assignments\Models\Certificate;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchFeedback;
use App\Domains\Batches\Models\BatchGeofence;
use App\Domains\Batches\Models\DeviceWebhookCredential;
use App\Domains\Batches\Models\EnrolmentImportBatch;
use App\Domains\Batches\Models\StudentImportBatch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\IdCard;
use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Domains\Batches\Models\LivePoll;
use App\Domains\Batches\Models\LivePollOption;
use App\Domains\Batches\Models\LivePollResponse;
use App\Domains\Batches\Models\LiveRecording;
use App\Domains\Batches\Models\TeacherAttendanceFlag;
use App\Domains\Batches\Models\TimetableTemplate;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantStorageUsage;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Comms\Models\BlockedUser;
use App\Domains\Comms\Models\DirectMessage;
use App\Domains\Comms\Models\ForumPost;
use App\Domains\Comms\Models\ForumThread;
use App\Domains\Comms\Models\HelpDeskTicket;
use App\Domains\Comms\Models\HelpDeskTicketReply;
use App\Domains\Comms\Models\MessageReport;
use App\Domains\Comms\Models\Notice;
use App\Domains\Comms\Models\NoticeReadReceipt;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Comms\Models\ParentInviteCode;
use App\Domains\Comms\Models\Survey;
use App\Domains\Comms\Models\SurveyAnswer;
use App\Domains\Comms\Models\SurveyQuestion;
use App\Domains\Comms\Models\SurveyResponse;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseImportBatch;
use App\Domains\Content\Models\CourseReview;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonNote;
use App\Domains\Content\Models\LessonQuestion;
use App\Domains\Content\Models\LessonQuestionReply;
use App\Domains\Content\Models\LessonQuestionVote;
use App\Domains\Content\Models\LessonStreamSession;
use App\Domains\Content\Models\Program;
use App\Domains\Content\Models\Tag;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Content\Models\Wishlist;
use App\Domains\Fees\Models\FeeCoupon;
use App\Domains\Fees\Models\PaymentHistoryImportBatch;
use App\Domains\Operations\Models\Enquiry;
use App\Domains\Operations\Models\EnquiryFollowUp;
use App\Domains\Planner\Models\PlannerEvent;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\FeeRefund;
use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliatePayoutRequest;
use App\Domains\Growth\Models\AffiliateReferral;
use App\Domains\Growth\Models\CourseInterestSignup;
use App\Domains\Growth\Models\InstructorPayout;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Fees\Models\StudentSubscription;
use App\Domains\Security\Models\AccountSharingFlag;
use App\Domains\Security\Models\DeviceSession;
use App\Domains\Security\Models\GdprDeletionRequest;
use App\Domains\Security\Models\GdprExportRequest;
use App\Domains\Security\Models\LoginHistory;
use App\Domains\Tenancy\Models\FeatureFlag;
use App\Domains\Tenancy\Models\InstructorInvitation;
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
        FeeRefund::class,
        StudentMembershipPlan::class,
        StudentSubscription::class,
        AffiliateCode::class,
        AffiliateReferral::class,
        AffiliatePayoutRequest::class,
        InstructorPayout::class,
        CourseInterestSignup::class,
        ParentGuardianLink::class,
        ParentInviteCode::class,
        Assignment::class,
        AssignmentSubmission::class,
        Certificate::class,
        TenantSubscription::class,
        SubscriptionInvoice::class,
        TenantStorageUsage::class,
        DeviceSession::class,
        LoginHistory::class,
        AccountSharingFlag::class,
        GdprExportRequest::class,
        GdprDeletionRequest::class,
        Category::class,
        Tag::class,
        Wishlist::class,
        Program::class,
        LessonQuestion::class,
        LessonQuestionReply::class,
        LessonQuestionVote::class,
        LessonNote::class,
        DirectMessage::class,
        BlockedUser::class,
        MessageReport::class,
        CourseReview::class,
        LessonStreamSession::class,
        InstituteZoomConnection::class,
        LiveRecording::class,
        LivePoll::class,
        LivePollOption::class,
        LivePollResponse::class,
        InstructorInvitation::class,
        TimetableTemplate::class,
        ForumThread::class,
        ForumPost::class,
        BatchFeedback::class,
        StudentImportBatch::class,
        Enquiry::class,
        EnquiryFollowUp::class,
        IdCard::class,
        Notice::class,
        NoticeReadReceipt::class,
        BatchGeofence::class,
        DeviceWebhookCredential::class,
        TeacherAttendanceFlag::class,
        QuizProctoringEvent::class,
        TestSeries::class,
        OmrTemplate::class,
        OmrSheetScan::class,
        CourseOutlineDraft::class,
        LessonRevisionNote::class,
        PhotoDoubtQuery::class,
        MonthlyReportCard::class,
        Survey::class,
        SurveyQuestion::class,
        SurveyResponse::class,
        SurveyAnswer::class,
        HelpDeskTicket::class,
        HelpDeskTicketReply::class,
        PlannerEvent::class,
        GamificationProfile::class,
        Badge::class,
        StudentBadge::class,
        FeatureFlag::class,
        ApiKey::class,
        WebhookEndpoint::class,
        WebhookDelivery::class,
        CourseImportBatch::class,
        EnrolmentImportBatch::class,
        PaymentHistoryImportBatch::class,
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
            FeeRefund::class => [
                'fee_payment_id' => FeePayment::create([
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
                        'status' => 'paid',
                        'paid_at' => now(),
                    ])->id,
                    'provider_order_id' => 'order_row_'.uniqid(),
                    'idempotency_key' => 'idem_'.uniqid(),
                    'amount' => 118,
                    'status' => 'captured',
                ])->id,
                'requested_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'amount' => 118,
                'reason' => 'Row reason',
            ],
            StudentMembershipPlan::class => [
                'name' => 'Row Plan',
                'price' => 499,
                'billing_interval' => 'monthly',
                'trial_days' => 7,
                'status' => 'active',
            ],
            StudentSubscription::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'student_membership_plan_id' => StudentMembershipPlan::create([
                    'name' => 'Parent Plan',
                    'price' => 499,
                    'billing_interval' => 'monthly',
                    'trial_days' => 7,
                    'status' => 'active',
                ])->id,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ],
            AffiliateCode::class => [
                'owner_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'code' => 'ROW'.strtoupper(substr(uniqid(), -6)),
                'commission_type' => 'percentage',
                'commission_value' => 10,
                'status' => 'active',
            ],
            AffiliateReferral::class => [
                'affiliate_code_id' => AffiliateCode::create([
                    'owner_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'code' => 'PAR'.strtoupper(substr(uniqid(), -6)),
                    'commission_type' => 'percentage',
                    'commission_value' => 10,
                    'status' => 'active',
                ])->id,
                'referred_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'fee_payment_id' => FeePayment::create([
                    'fee_invoice_id' => FeeInvoice::create([
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
                    'status' => 'captured',
                ])->id,
                'commission_amount' => 0,
                'status' => 'pending',
            ],
            AffiliatePayoutRequest::class => [
                'affiliate_code_id' => AffiliateCode::create([
                    'owner_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'code' => 'PAY'.strtoupper(substr(uniqid(), -6)),
                    'commission_type' => 'percentage',
                    'commission_value' => 10,
                    'status' => 'active',
                ])->id,
                'amount' => 100,
                'status' => 'requested',
                'requested_at' => now(),
            ],
            InstructorPayout::class => [
                'instructor_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'basis' => 'salaried',
                'amount' => 5000,
                'status' => 'pending',
            ],
            CourseInterestSignup::class => [
                'course_id' => Course::create(['title' => 'Row', 'slug' => 'row-'.uniqid()])->id,
                'name' => 'Row Prospect',
                'email' => 'row-'.uniqid().'@example.com',
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
            TenantSubscription::class => [
                'plan_id' => Plan::create([
                    'code' => 'row-'.uniqid(),
                    'name' => 'Row Plan',
                    'seat_limit' => 5,
                    'storage_limit_gb' => 10,
                    'bandwidth_limit_gb_per_day' => 10,
                    'ai_spend_cap_usd_per_day' => 1,
                    'price_inr_per_month' => 999,
                ])->id,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ],
            SubscriptionInvoice::class => [
                'tenant_subscription_id' => TenantSubscription::create([
                    'plan_id' => Plan::create([
                        'code' => 'row-'.uniqid(),
                        'name' => 'Row Plan',
                        'seat_limit' => 5,
                        'storage_limit_gb' => 10,
                        'bandwidth_limit_gb_per_day' => 10,
                        'ai_spend_cap_usd_per_day' => 1,
                        'price_inr_per_month' => 999,
                    ])->id,
                    'status' => 'active',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                ])->id,
                'plan_id' => Plan::create([
                    'code' => 'row-'.uniqid(),
                    'name' => 'Row Plan',
                    'seat_limit' => 5,
                    'storage_limit_gb' => 10,
                    'bandwidth_limit_gb_per_day' => 10,
                    'ai_spend_cap_usd_per_day' => 1,
                    'price_inr_per_month' => 999,
                ])->id,
                'amount' => 999,
                'kind' => 'recurring',
                'status' => 'pending',
                'period_start' => now(),
                'period_end' => now()->addMonth(),
            ],
            TenantStorageUsage::class => [
                'bytes_used' => 0,
            ],
            DeviceSession::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'device_fingerprint' => 'fp-'.uniqid(),
                'last_seen_at' => now(),
            ],
            LoginHistory::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'ip_address' => '127.0.0.1',
                'successful' => true,
            ],
            AccountSharingFlag::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'distinct_device_count_7d' => 5,
                'flagged_at' => now(),
            ],
            GdprExportRequest::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'requested_by_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'pending',
            ],
            GdprDeletionRequest::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'requested_by_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'pending',
                'scheduled_for' => now()->addDays(30),
            ],
            Category::class => ['name' => 'Row', 'slug' => 'row-'.uniqid()],
            Tag::class => ['name' => 'Row', 'slug' => 'row-'.uniqid()],
            Wishlist::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'course_id' => Course::create(['title' => 'Row', 'slug' => 'row-'.uniqid()])->id,
            ],
            Program::class => ['title' => 'Row', 'slug' => 'row-'.uniqid(), 'price' => 999],
            LessonQuestion::class => [
                'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'body' => 'Row?',
            ],
            LessonQuestionReply::class => [
                'lesson_question_id' => LessonQuestion::create([
                    'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                    'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'body' => 'Row?',
                ])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'body' => 'Row.',
            ],
            LessonQuestionVote::class => [
                'lesson_question_id' => LessonQuestion::create([
                    'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                    'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'body' => 'Row?',
                ])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
            ],
            LessonNote::class => [
                'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'video', 'position' => 0])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'timestamp_s' => 10,
            ],
            DirectMessage::class => [
                'sender_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'recipient_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'body' => 'Row',
            ],
            BlockedUser::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'blocked_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
            ],
            MessageReport::class => [
                'reporter_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'direct_message_id' => DirectMessage::create([
                    'sender_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'recipient_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'body' => 'Row',
                ])->id,
                'reason' => 'Row reason',
            ],
            CourseReview::class => [
                'course_id' => Course::create(['title' => 'Row', 'slug' => 'row-'.uniqid()])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'rating' => 5,
            ],
            LessonStreamSession::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'video', 'position' => 0])->id,
                'started_at' => now(),
                'last_heartbeat_at' => now(),
            ],
            InstituteZoomConnection::class => [
                'account_id' => 'acct_'.uniqid(),
                'access_token' => 'row-access-token',
                'refresh_token' => 'row-refresh-token',
                'token_expires_at' => now()->addHour(),
                'connected_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
            ],
            LiveRecording::class => [
                'batch_session_id' => BatchSession::create([
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
                    'live_provider' => 'zoom_meetings_oauth',
                ])->id,
                'status' => 'pending',
            ],
            LivePoll::class => [
                'batch_session_id' => BatchSession::create([
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
                    'live_provider' => 'zoom_meetings_oauth',
                ])->id,
                'question' => 'Row poll question?',
                'status' => 'draft',
            ],
            LivePollOption::class => [
                'live_poll_id' => LivePoll::create([
                    'batch_session_id' => BatchSession::create([
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
                        'live_provider' => 'zoom_meetings_oauth',
                    ])->id,
                    'question' => 'Parent poll question?',
                    'status' => 'draft',
                ])->id,
                'label' => 'Option A',
                'position' => 0,
            ],
            LivePollResponse::class => (function () {
                $poll = LivePoll::create([
                    'batch_session_id' => BatchSession::create([
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
                        'live_provider' => 'zoom_meetings_oauth',
                    ])->id,
                    'question' => 'Parent poll question?',
                    'status' => 'open',
                ]);

                $option = LivePollOption::create([
                    'live_poll_id' => $poll->id,
                    'label' => 'Option A',
                    'position' => 0,
                ]);

                return [
                    'live_poll_id' => $poll->id,
                    'live_poll_option_id' => $option->id,
                    'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                ];
            })(),
            InstructorInvitation::class => [
                'email' => 'row-'.uniqid().'@example.com',
                'token' => 'tok_'.uniqid(),
                'invited_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'pending',
                'expires_at' => now()->addDays(14),
            ],
            TimetableTemplate::class => [
                'title' => 'Row',
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'duration_minutes' => 60,
            ],
            ForumThread::class => [
                'batch_id' => Batch::create([
                    'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                    'title' => 'Parent',
                    'slug' => 'parent-'.uniqid(),
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addMonth()->toDateString(),
                    'self_enrol_mode' => 'manual_only',
                    'status' => 'draft',
                ])->id,
                'title' => 'Row',
                'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
            ],
            ForumPost::class => [
                'forum_thread_id' => ForumThread::create([
                    'batch_id' => Batch::create([
                        'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                        'title' => 'Parent',
                        'slug' => 'parent-'.uniqid(),
                        'starts_on' => now()->toDateString(),
                        'ends_on' => now()->addMonth()->toDateString(),
                        'self_enrol_mode' => 'manual_only',
                        'status' => 'draft',
                    ])->id,
                    'title' => 'Parent',
                    'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                ])->id,
                'depth' => 0,
                'author_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'body' => 'Row',
            ],
            BatchFeedback::class => [
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
                'rating' => 5,
            ],
            StudentImportBatch::class => [
                'uploaded_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'pending',
            ],
            Enquiry::class => [
                'name' => 'Row',
                'email' => 'row-'.uniqid().'@example.com',
                'status' => 'new',
            ],
            EnquiryFollowUp::class => [
                'enquiry_id' => Enquiry::create(['name' => 'Parent', 'email' => 'parent-'.uniqid().'@example.com', 'status' => 'new'])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'note' => 'Row',
            ],
            IdCard::class => [
                'enrolment_id' => Enrolment::create([
                    'batch_id' => Batch::create([
                        'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                        'title' => 'Parent',
                        'slug' => 'parent-'.uniqid(),
                        'starts_on' => now()->toDateString(),
                        'ends_on' => now()->addMonth()->toDateString(),
                        'self_enrol_mode' => 'manual_only',
                        'status' => 'published',
                    ])->id,
                    'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'status' => 'active',
                    'source' => 'manual_add',
                    'requested_at' => now(),
                ])->id,
                'card_number' => 'ID-'.strtoupper(uniqid()),
                'disk_path' => 'id-cards/row.pdf',
                'issued_at' => now(),
            ],
            Notice::class => [
                'title' => 'Row',
                'body' => 'Row body',
                'audience_roles' => ['student'],
                'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
            ],
            NoticeReadReceipt::class => [
                'notice_id' => Notice::create([
                    'title' => 'Parent',
                    'body' => 'Parent body',
                    'audience_roles' => ['student'],
                    'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                ])->id,
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'read_at' => now(),
            ],
            BatchGeofence::class => [
                'batch_id' => Batch::create([
                    'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                    'title' => 'Parent',
                    'slug' => 'parent-'.uniqid(),
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addMonth()->toDateString(),
                    'self_enrol_mode' => 'manual_only',
                    'status' => 'draft',
                ])->id,
                'latitude' => 12.9716,
                'longitude' => 77.5946,
                'radius_meters' => 100,
            ],
            DeviceWebhookCredential::class => [
                'device_label' => 'Row Device',
                'webhook_secret' => 'secret-'.uniqid(),
            ],
            TeacherAttendanceFlag::class => (function () {
                $instructor = User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()]);
                $batch = Batch::create([
                    'course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => 'published'])->id,
                    'instructor_id' => $instructor->id,
                    'title' => 'Parent',
                    'slug' => 'parent-'.uniqid(),
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addMonth()->toDateString(),
                    'self_enrol_mode' => 'manual_only',
                    'status' => 'draft',
                ]);
                $session = BatchSession::create([
                    'batch_id' => $batch->id,
                    'starts_at' => now()->subDay(),
                    'duration_minutes' => 60,
                    'position' => 0,
                ]);

                return [
                    'batch_session_id' => $session->id,
                    'instructor_id' => $instructor->id,
                    'flagged_at' => now(),
                ];
            })(),
            QuizProctoringEvent::class => [
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
                'event_type' => 'tab_switch',
                'occurred_at' => now(),
            ],
            TestSeries::class => ['title' => 'Row Series'],
            OmrTemplate::class => [
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
                    'title' => 'Row Quiz',
                    'blueprint' => [],
                    'time_limit_minutes' => 30,
                    'max_attempts' => 3,
                ])->id,
                'question_count' => 10,
                'options_per_question' => 4,
                'roll_number_digits' => 6,
                'layout' => OmrTemplate::generateLayout(10, 4, 6),
            ],
            OmrSheetScan::class => (function () {
                $quiz = Quiz::create([
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
                    'title' => 'Row Quiz',
                    'blueprint' => [],
                    'time_limit_minutes' => 30,
                    'max_attempts' => 3,
                ]);
                $template = OmrTemplate::create([
                    'quiz_id' => $quiz->id,
                    'question_count' => 10,
                    'options_per_question' => 4,
                    'roll_number_digits' => 6,
                    'layout' => OmrTemplate::generateLayout(10, 4, 6),
                ]);

                return [
                    'omr_template_id' => $template->id,
                    'uploaded_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'image_disk_path' => 'omr-scans/row.png',
                    'status' => 'pending',
                ];
            })(),
            CourseOutlineDraft::class => [
                'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'topic' => 'Row',
                'status' => 'draft',
            ],
            LessonRevisionNote::class => [
                'lesson_id' => Lesson::create(['course_section_id' => CourseSection::create(['course_id' => Course::create(['title' => 'Parent', 'slug' => 'parent-'.uniqid()])->id, 'title' => 'Parent', 'position' => 0])->id, 'title' => 'Row', 'type' => 'text', 'position' => 0])->id,
                'source_artifact_version' => 1,
                'status' => 'ready',
            ],
            PhotoDoubtQuery::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'image_disk_path' => 'photo-doubts/row.jpg',
                'status' => 'pending',
            ],
            MonthlyReportCard::class => [
                'student_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'period_month' => now()->startOfMonth()->toDateString(),
            ],
            Survey::class => [
                'title' => 'Row Survey',
                'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
            ],
            SurveyQuestion::class => [
                'survey_id' => Survey::create([
                    'title' => 'Parent',
                    'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                ])->id,
                'prompt' => 'Row question',
                'type' => 'text',
            ],
            SurveyResponse::class => [
                'survey_id' => Survey::create([
                    'title' => 'Parent',
                    'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                ])->id,
                'respondent_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
            ],
            SurveyAnswer::class => [
                'survey_response_id' => SurveyResponse::create([
                    'survey_id' => Survey::create([
                        'title' => 'Parent',
                        'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    ])->id,
                    'respondent_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                ])->id,
                'survey_question_id' => SurveyQuestion::create([
                    'survey_id' => Survey::create([
                        'title' => 'Parent',
                        'created_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    ])->id,
                    'prompt' => 'Row question',
                    'type' => 'text',
                ])->id,
                'value' => 'Row answer',
            ],
            HelpDeskTicket::class => [
                'requester_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'subject' => 'Row ticket',
                'body' => 'Row body',
                'sla_due_at' => now()->addHours(48),
            ],
            HelpDeskTicketReply::class => [
                'ticket_id' => HelpDeskTicket::create([
                    'requester_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                    'subject' => 'Parent',
                    'body' => 'Parent body',
                    'sla_due_at' => now()->addHours(48),
                ])->id,
                'author_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'body' => 'Row reply',
            ],
            PlannerEvent::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'title' => 'Row event',
                'starts_at' => now()->addDay(),
            ],
            GamificationProfile::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'xp_total' => 0,
                'level' => 1,
            ],
            Badge::class => [
                'key' => 'row-badge-'.uniqid(),
                'name' => 'Row Badge',
                'xp_threshold' => 100,
            ],
            StudentBadge::class => [
                'user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'badge_id' => Badge::create([
                    'key' => 'parent-badge-'.uniqid(),
                    'name' => 'Parent Badge',
                    'xp_threshold' => 100,
                ])->id,
                'awarded_at' => now(),
            ],
            FeatureFlag::class => ['key' => 'row-flag-'.uniqid(), 'enabled' => false],
            ApiKey::class => ['name' => 'Row Key', 'key_hash' => hash('sha256', 'row-'.uniqid())],
            CourseImportBatch::class => [
                'uploaded_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'completed',
            ],
            EnrolmentImportBatch::class => [
                'uploaded_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'completed',
            ],
            PaymentHistoryImportBatch::class => [
                'uploaded_by_user_id' => User::factory()->create(['tenant_id' => app(CurrentTenant::class)->id()])->id,
                'status' => 'completed',
            ],
            WebhookEndpoint::class => ['url' => 'https://example.com/row-'.uniqid(), 'secret' => 'secret-'.uniqid()],
            WebhookDelivery::class => [
                'webhook_endpoint_id' => WebhookEndpoint::create(['url' => 'https://example.com/parent', 'secret' => 'parent-secret'])->id,
                'event_type' => 'row.event',
                'payload' => ['row' => true],
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
            FeeRefund::class => ['status' => 'tampered'],
            StudentMembershipPlan::class => ['name' => 'tampered'],
            StudentSubscription::class => ['status' => 'tampered'],
            AffiliateCode::class => ['status' => 'disabled'],
            AffiliateReferral::class => ['status' => 'tampered'],
            AffiliatePayoutRequest::class => ['status' => 'tampered'],
            InstructorPayout::class => ['status' => 'tampered'],
            CourseInterestSignup::class => ['name' => 'tampered'],
            ParentGuardianLink::class => ['relationship' => 'tampered'],
            ParentInviteCode::class => ['redeemed_at' => now()],
            Assignment::class => ['title' => 'tampered'],
            AssignmentSubmission::class => ['feedback' => 'tampered'],
            Certificate::class => ['pdf_disk_path' => 'tampered.pdf'],
            TenantSubscription::class => ['status' => 'tampered'],
            SubscriptionInvoice::class => ['status' => 'tampered'],
            TenantStorageUsage::class => ['bytes_used' => 999999],
            DeviceSession::class => ['device_label' => 'tampered'],
            LoginHistory::class => ['successful' => false],
            AccountSharingFlag::class => ['distinct_device_count_7d' => 999],
            GdprExportRequest::class => ['status' => 'tampered'],
            GdprDeletionRequest::class => ['status' => 'tampered'],
            Category::class => ['name' => 'tampered'],
            Tag::class => ['name' => 'tampered'],
            Wishlist::class => ['course_id' => 999999],
            Program::class => ['title' => 'tampered'],
            LessonQuestion::class => ['body' => 'tampered'],
            LessonQuestionReply::class => ['body' => 'tampered'],
            LessonQuestionVote::class => ['created_at' => now()->subDay()],
            LessonNote::class => ['body' => 'tampered'],
            DirectMessage::class => ['body' => 'tampered'],
            BlockedUser::class => ['created_at' => now()->subDay()],
            MessageReport::class => ['status' => 'tampered'],
            CourseReview::class => ['rating' => 1],
            LessonStreamSession::class => ['ended_at' => now()],
            InstituteZoomConnection::class => ['account_id' => 'tampered'],
            LiveRecording::class => ['status' => 'tampered'],
            LivePoll::class => ['question' => 'tampered'],
            LivePollOption::class => ['label' => 'tampered'],
            LivePollResponse::class => ['created_at' => now()->subDay()],
            InstructorInvitation::class => ['status' => 'tampered'],
            TimetableTemplate::class => ['title' => 'tampered'],
            ForumThread::class => ['title' => 'tampered'],
            ForumPost::class => ['body' => 'tampered'],
            BatchFeedback::class => ['rating' => 1],
            StudentImportBatch::class => ['status' => 'tampered'],
            Enquiry::class => ['status' => 'tampered'],
            EnquiryFollowUp::class => ['note' => 'tampered'],
            IdCard::class => ['disk_path' => 'tampered.pdf'],
            Notice::class => ['title' => 'tampered'],
            NoticeReadReceipt::class => ['read_at' => now()->subDay()],
            BatchGeofence::class => ['radius_meters' => 999],
            DeviceWebhookCredential::class => ['device_label' => 'tampered'],
            TeacherAttendanceFlag::class => ['status' => 'resolved'],
            QuizProctoringEvent::class => ['event_type' => 'copy'],
            TestSeries::class => ['title' => 'tampered'],
            OmrTemplate::class => ['question_count' => 20],
            OmrSheetScan::class => ['status' => 'failed'],
            CourseOutlineDraft::class => ['status' => 'approved'],
            LessonRevisionNote::class => ['status' => 'failed'],
            PhotoDoubtQuery::class => ['status' => 'failed'],
            MonthlyReportCard::class => ['status' => 'failed'],
            Survey::class => ['title' => 'tampered'],
            SurveyQuestion::class => ['prompt' => 'tampered'],
            SurveyResponse::class => ['submitted_at' => now()],
            // `Model::where()->update()` (CrossTenantTest's own update path)
            // bypasses Eloquent's 'array' cast mutator entirely — a bare PHP
            // string sent as-is to a jsonb column fails Postgres' implicit
            // text->jsonb cast (unquoted `tampered` isn't valid JSON text).
            // Pre-encoding produces a valid JSON string literal instead.
            SurveyAnswer::class => ['value' => json_encode('tampered')],
            HelpDeskTicket::class => ['subject' => 'tampered'],
            HelpDeskTicketReply::class => ['body' => 'tampered'],
            PlannerEvent::class => ['title' => 'tampered'],
            GamificationProfile::class => ['xp_total' => 999999],
            Badge::class => ['name' => 'tampered'],
            StudentBadge::class => ['awarded_at' => now()->subDay()],
            FeatureFlag::class => ['enabled' => true],
            ApiKey::class => ['name' => 'tampered'],
            CourseImportBatch::class => ['status' => 'failed'],
            EnrolmentImportBatch::class => ['status' => 'failed'],
            PaymentHistoryImportBatch::class => ['status' => 'failed'],
            WebhookEndpoint::class => ['status' => 'disabled'],
            WebhookDelivery::class => ['status' => 'failed'],
            default => throw new RuntimeException("No update attributes registered for [{$modelClass}]."),
        };
    }
}
