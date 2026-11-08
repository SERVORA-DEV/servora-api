<?php

// The Terms of Service and Privacy Policy, served to the web app and the
// mobile app by GET /api/legal (LegalController) so both always show the same
// text, and stamped on each account at sign-up (users.terms_version).
//
// DRAFT — written from how the product works, not by a lawyer. Have it
// reviewed before launch. Questions for counsel are marked TODO in comments
// here, never in the text readers see. The details only the operator knows
// (name, address, email, DPO, court city) come from .env; until they are set
// readers see a [placeholder], and `php artisan legal:check` fails.
//
// Each document has a `summary` (the few facts a reader most needs, shown
// above the full text) and its sections an `audience`: 'all' (default),
// 'client' or 'owner', so the apps can show a reader only their part.
//
// When the wording changes in a way people should re-read, bump `version`
// (and `effective_date`): new sign-ups are recorded against the new version.
//
// Placeholders {operator}, {address} and {email} are replaced with the
// values below. A body entry that is a string is a paragraph; one that is an
// array is a bulleted list.

return [

    'version' => '2026-10-02',
    'effective_date' => '2026-10-02',

    // Who runs Servora. Set these in .env before launch.
    'operator' => [
        'name' => env('LEGAL_OPERATOR_NAME', '[Company name]'),
        'address' => env('LEGAL_OPERATOR_ADDRESS', '[Business address]'),
        'email' => env('LEGAL_CONTACT_EMAIL', '[Contact email]'),
        'dpo' => env('LEGAL_DPO_NAME', '[DPO name]'),
        'venue' => env('LEGAL_VENUE_CITY', '[city, Philippines]'),
    ],

    'documents' => [

        // ─────────────────────────────────────────────────────────────────
        'terms' => [
            'title' => 'Terms of Service',
            'revision_date' => '2026-11-08',
            'intro' => 'SERVORA is a Spa Management Platform for spa businesses, their staff and their clients. These Terms describe the use of the SERVORA web app, mobile app and related services. In these Terms, "SERVORA", "we", "us" and "our" refer to the team operating the platform. Account registration requires acceptance of the Terms of Service and Privacy Policy.',
            'summary' => [
                'SERVORA provides management and reservation software. Each spa provides its treatments and manages its services, prices and booking policies.',
                'Clients pay the spa directly. SERVORA records spa payments and receipts; subscription payments for using the platform are separate.',
                'Business features depend on subscription or trial access, plan features, account permissions and applicable restrictions. Public marketplace listing requires verification.',
                'Clients can change eligible scheduled bookings within the spa\'s rules, before check-in and before the scheduled start time.',
                'Each spa configures its rewards within SERVORA\'s program rules and available features.',
                'Client account deletion anonymizes and deactivates the platform login. Spa visit and payment records may remain. For account or platform questions, contact servoraofcl@gmail.com.',
            ],
            'sections' => [
                [
                    'heading' => 'What SERVORA is',
                    'body' => [
                        'SERVORA brings spa reservations and day-to-day management into a shared digital record. Owners and authorized staff use the web app to manage branches, services, facilities, staff, appointments, walk-in queues, billing, customer programs and performance reports, according to their access.',
                        'Platform administrators manage subscription plan offerings, review owner, business and branch verification, and manage marketplace visibility through the available platform controls.',
                        'Clients use the mobile app to discover available spa listings through maps and search, view service and therapist availability, book appointments, follow queue progress, and view receipts, notifications and rewards. Available services may include visits at the spa and home-service appointments.',
                        'SERVORA provides the software functions. Each spa provides its treatments and manages its service information, prices, staff and operating policies.',
                    ],
                ],
                [
                    'heading' => 'Your account',
                    'body' => [
                        [
                            'Give accurate account information and keep it up to date.',
                            // Existing eligibility policy; age enforcement remains a separate review item.
                            'You must be at least 18 years old to create an account.',
                            'Email-and-password registration requires email verification before sign-in. Supported Google sign-in uses the verified email confirmed by Google. Additional sign-in verification applies where enabled.',
                            'Keep your password and sign-in credentials private. If you suspect unauthorized access, contact servoraofcl@gmail.com.',
                            'Do not share a login. Owners can create separate manager and front-desk accounts, subject to plan capacity and permissions. Therapist records and assignments are managed separately from these login accounts.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Spa owners: verification',
                    'audience' => 'owner',
                    'body' => [
                        'Business setup, verification and subscription access are separate parts of using SERVORA. Business features depend on subscription or trial access, account permissions and applicable restrictions. The web onboarding flow may direct a new owner to complete verification before opening plans or other modules.',
                        'Public marketplace listing requires verification of the owner\'s identity, the business and the branch, together with the required plan access and listing readiness. Having access to internal business features does not by itself make a business publicly listed.',
                        'Owner verification includes government-issued identification and face images captured through the verification flow for review. Business registration documents depend on business type: DTI documentation for a sole proprietorship, or SEC documentation for a corporation or partnership. Branch verification includes the required mayor\'s permit.',
                        'Submit accurate, genuine and current information and documents. Submissions may be approved, rejected or returned for resubmission. Owners manage the staff accounts and permissions available within their business.',
                    ],
                ],
                [
                    'heading' => 'Spa owners: plans and payment',
                    'audience' => 'owner',
                    'body' => [
                        [
                            'Business features require an active paid subscription, an active free trial, or an applicable paid-subscription grace period. Access also depends on the plan, account permissions and business or branch restrictions.',
                            'Plans define included features and capacity, including branches and manager/front-desk login accounts. Available plans, prices, capacity options and billing choices are shown in SERVORA.',
                            'Paid plans support monthly or yearly billing where offered. Prices are displayed in Philippine pesos. Subscription payments are processed through the platform\'s payment provider. A paid term or upgrade takes effect after payment confirmation.',
                            'Where available, an eligible owner may explicitly start a free trial. It does not start automatically at registration. Its duration is shown before it starts. Eligibility checks include previous trial use by the owner and previous subscriptions for the business.',
                            'A free trial is not charged automatically and has no post-trial grace period. Purchasing a paid plan ends the trial immediately; unused trial days are not added to the paid term.',
                            'Automatic renewal can be enabled with an eligible saved payment method. GCash checkout is one-time only. Cards and Maya are supported reusable payment choices in the current checkout.',
                            'When automatic renewal is enabled, SERVORA attempts payment when the subscription becomes due. Failed attempts may be retried during the applicable grace period. Renewal depends on payment-method validity, plan availability and capacity requirements. Suspended businesses are excluded from automatic renewal charges.',
                            'Turning automatic renewal off stops future automatic renewal attempts. It does not immediately end the current paid term or itself issue a refund.',
                            'When plan changes are enabled, an owner may request a change while the paid term remains active. An eligible upgrade applies after payment confirmation, using the displayed price difference, while retaining the existing billing cycle and due date. There is no separate short post-purchase window for requesting a change.',
                            'Downgrades and billing-cycle changes are scheduled for the next term. A scheduled change may be changed or withdrawn before the current term ends. The next term begins only after the applicable payment is confirmed.',
                            'A smaller plan may require the owner to choose which branches and staff logins to retain. Other branches or logins may be placed on hold rather than deleted. Included features may also change with the new plan.',
                            'Paid subscriptions may receive a configured grace period after expiry. Trials and subscriptions ended by declining updated plan terms do not receive that grace period. When access ends, the owner can use the available subscription and payment screens to arrange continued access.',
                            'Owners may accept or decline a notified replacement plan. Declining lets the current term end at expiry without automatic renewal into that replacement plan. The existing term is not replaced immediately by the new plan terms.',
                            'SERVORA does not provide automated subscription-refund requests or a self-service feature for immediately cancelling a paid term. For subscription payment concerns or refund requests, contact servoraofcl@gmail.com. This describes the available platform features and does not exclude any rights available under applicable law.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Clients: bookings and visits',
                    'audience' => 'client',
                    'body' => [
                        [
                            'Each spa manages its available services, prices, opening hours and booking policies. Booking choices depend on service, therapist and branch availability.',
                            'Clients can cancel or reschedule eligible reservations through the app only while the booking remains scheduled, before check-in and before its scheduled start time, and within the spa\'s permitted change window and rescheduling limits. When app changes are unavailable, contact the spa.',
                            'Authorized spa staff may update or cancel bookings through their available workflows. Booking changes are recorded in SERVORA, with notifications generated through the applicable notification features.',
                            'Repeated missed bookings may pause further app bookings under the spa\'s booking policy. Contact the spa when this restriction applies.',
                            'Staff manage therapist schedules, availability and assignments through SERVORA. Queue progress follows check-in and service updates. Queue positions and waiting times are estimates and may change as visits progress.',
                            'For home-service bookings, clients provide the service address and map pin. These details are available to the spa for arranging and providing the visit. Applicable home-service charges appear in the booking.',
                            'Clients pay the spa directly using the methods it accepts. SERVORA records payments, receipts and applicable adjustments entered by authorized spa staff. These treatment payments are separate from the spa\'s subscription payments to use SERVORA.',
                            'Questions about treatments, spa prices, booking arrangements or treatment refunds should be raised with the spa. SERVORA does not collect client treatment payments through the app.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Loyalty points, vouchers, discounts and memberships',
                    'audience' => 'client',
                    'body' => [
                        'Each spa configures its customer programs within the options available in SERVORA. Eligibility, earning rules, redemption conditions, branch or booking-channel restrictions, and expiry depend on the applicable program.',
                        'Loyalty points are earned by eligible app-linked clients under the spa\'s configured rules. Vouchers, discounts and memberships may have separate eligibility and usage conditions. Rewards are associated with the spa business that issued them.',
                        'Voiding, reopening or refunding a bill may reverse earned points or cancel unused rewards generated by that bill. Applicable refund processing may restore a voucher previously used on the bill.',
                        'Program availability also depends on the business\'s settings and plan features. When those features are unavailable, balances may remain stored while earning or redemption is unavailable. Program expiry conditions still apply.',
                    ],
                ],
                [
                    'heading' => 'Listings and reviews',
                    'body' => [
                        'Marketplace visibility depends on verification, plan access, branch operating status and listing readiness. Each spa maintains its listing information, services, prices and uploaded images, and must have permission to use the content it uploads.',
                        'Clients can submit a review through an eligible completed appointment. The current system limits repeat ratings of the same branch by the same client.',
                        'Reviews should describe an actual visit and must not contain abusive, misleading or unrelated content. To report a concern about a review, contact servoraofcl@gmail.com.',
                    ],
                ],
                [
                    'heading' => 'Appropriate use of SERVORA',
                    'body' => [
                        [
                            'Use SERVORA only for lawful spa management and reservation activities.',
                            'Do not submit false information or documents, impersonate another person, or misrepresent a business.',
                            'Do not access accounts, businesses or records without authorization. Use staff access only within the permissions assigned to you.',
                            'Do not disrupt the service, bypass access restrictions or misuse another person\'s sign-in credentials.',
                            'Do not upload abusive content or content that infringes someone else\'s rights.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Data that businesses put into SERVORA',
                    'audience' => 'owner',
                    'body' => [
                        'Spa businesses enter and manage staff, client, appointment and billing records in SERVORA. Access to those records depends on business association, branch access, roles and permissions. Businesses should enter only information they are authorized to collect and use.',
                        'The Privacy Policy explains personal information handled through the platform, its uses, sharing and available privacy requests. For privacy questions, contact servoraofcl@gmail.com.',
                    ],
                ],
                [
                    'heading' => 'Suspension and closing an account',
                    'body' => [
                        'Account, business or branch restrictions can limit sign-in, operations or marketplace availability. Subscription expiry and plan-capacity holds are separate restrictions. Contact servoraofcl@gmail.com with questions about a platform restriction.',
                        'Clients can delete their account through the mobile app after password confirmation. The process anonymizes and deactivates the platform login, revokes its sign-in tokens and unlinks it from spa client records. Spa visit and payment records may remain; account deletion does not erase all records held by a spa.',
                        'Owners who want to close a business account should contact servoraofcl@gmail.com. Submitting a closure request is not confirmation that the business has been closed or that automatic renewal has been disabled. Automatic renewal can be managed through the subscription settings.',
                    ],
                ],
                [
                    'heading' => 'Availability and changes to the service',
                    'body' => [
                        'SERVORA\'s available features depend on the plan, permissions, business settings and current service availability. Booking availability and queue information change as staff and clients update operational records.',
                        'If you encounter a platform problem, contact servoraofcl@gmail.com. For immediate questions about a visit or booking, contact the spa.',
                    ],
                ],
                [
                    'heading' => 'SERVORA and spa responsibilities',
                    'body' => [
                        'SERVORA provides software for reservations, therapist and queue management, transaction recording, customer programs and business reporting. Each spa manages its staff, service delivery, prices, booking policies and the accuracy of the records it enters.',
                        'Platform questions can be sent to servoraofcl@gmail.com. Questions about spa operations and treatments should be raised with the spa. This description does not remove responsibilities imposed by applicable law.',
                    ],
                ],
                [
                    'heading' => 'Governing law',
                    'body' => [
                        'These Terms are governed by the laws of the Republic of the Philippines. They do not designate a particular court location or limit rights available under applicable law.',
                    ],
                ],
                [
                    'heading' => 'Changes to these Terms',
                    'body' => [
                        'The revision date at the top identifies when these Terms were last updated. Review the current Terms when using SERVORA. For questions about a change, contact servoraofcl@gmail.com.',
                    ],
                ],
                [
                    'heading' => 'Contact Us',
                    'body' => [
                        'If you have questions, concerns, or requests regarding these Terms of Service or the use of SERVORA, please contact:',
                        'SERVORA',
                        'Spa Management Platform',
                        'Email: servoraofcl@gmail.com',
                    ],
                ],
            ],
        ],

        // ─────────────────────────────────────────────────────────────────
        'privacy' => [
            'title' => 'Privacy Policy',
            'intro' => 'SERVORA is a Spa Management Platform. This policy explains what personal information is collected through the SERVORA web app and mobile app, why it is collected, who it is shared with, and the choices you have. In this policy, "we", "us" and "our" refer to the team operating SERVORA. We handle personal information in line with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173).',
            'summary' => [
                'We do not sell personal information.',
                'A client\'s location is used only to show spas nearby, and only if allowed. It is not shared with spas.',
                'When a client books, the spa sees the client\'s name, phone number and booking details.',
                'Owners\' IDs, face scans and business documents are stored privately. Only the owner and the Servora administrators who review verifications can open them.',
                'You may access, correct and ask to erase your information, and complain to the National Privacy Commission. Write to servoraofcl@gmail.com.',
            ],
            'sections' => [
                [
                    'heading' => 'Information we collect',
                    'body' => [
                        'From everyone with an account:',
                        [
                            'Your email address and password (the password is stored in hashed form, never in readable text).',
                            'Profile details you add, such as your name, phone number, gender, date of birth and photo.',
                            'Sign-in records: the date and time, device and browser type, and IP address of each sign-in, and your active sessions.',
                            'If you choose "Continue with Google", the email address Google confirms for you.',
                        ],
                        'From spa owners, for verification:',
                        [
                            'A government-issued ID (front and back) and a face scan taken in the app to confirm the ID is yours.',
                            'Business documents: the DTI Business Name Certificate and each branch\'s mayor\'s permit, with the business name, address and location.',
                        ],
                        'From spa businesses, as they use Servora:',
                        [
                            'Staff records: names, contact details, roles, schedules, attendance, the services each staff member performed and their commission.',
                            'Client records the spa keeps: names, contact details, appointments, bills, payments recorded and program balances.',
                            'Subscription and billing records for the business\'s plan. Card and e-wallet details are handled by our payment provider; we do not store full card numbers.',
                        ],
                        'From clients using the mobile app:',
                        [
                            'Your bookings, queue status, receipts, reviews, and your points, vouchers and memberships with each spa.',
                            'Your location, only while you use the app and only if you allow it, to show spas near you. It is not shared with spas.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Why we use it',
                    'body' => [
                        [
                            'To create and secure your account, including email verification and, where enabled, two-factor sign-in.',
                            'To confirm that a spa business and its owner are real before it can operate on Servora.',
                            'To run the features you use: bookings, queues, billing, staff management, reports and customer programs.',
                            'To process subscription payments and send receipts and reminders.',
                            'To send service messages, such as verification emails, booking updates and security alerts.',
                            'To keep an audit trail of important actions, prevent fraud and abuse, and fix problems.',
                            'To meet legal obligations.',
                        ],
                        'We rely on your consent, on what is needed to provide the service you asked for, on our legitimate interest in keeping Servora safe and working, and on legal requirements, as the case may be.',
                    ],
                ],
                [
                    'heading' => 'Who we share it with',
                    'body' => [
                        [
                            'Spas you book with: when a client books, the spa sees the client\'s name and phone number and the booking details, so it can serve and contact the client.',
                            'Staff of the same business: owners, managers and front-desk staff see the records of their own business and branches, according to the permissions the owner sets.',
                            'Service providers that process data for us: a payment provider (Xendit) for subscription payments, a file-storage provider (Cloudinary) for uploaded documents and images, an email delivery provider for emails, and our hosting and database providers.',
                            'Map services: showing maps and looking up addresses sends map requests to OpenStreetMap-based services.',
                            'Authorities, when the law requires it.',
                        ],
                        'We do not sell personal information. Some providers may store or process data outside the Philippines; where they do, we require them to protect it.',
                    ],
                ],
                [
                    'heading' => 'Verification documents',
                    'audience' => 'owner',
                    'body' => [
                        'IDs, face scans and business documents are used only to verify the owner and the business. They are stored privately and can be opened only by the owner who submitted them and by Servora administrators who review verifications.',
                    ],
                ],
                [
                    'heading' => 'How long we keep it',
                    'body' => [
                        // TODO(counsel): set specific retention periods for verification documents, billing records and sign-in logs.
                        'We keep personal information for as long as your account or your business\'s account is active, and afterwards for as long as needed for legal, tax, accounting and dispute purposes.',
                        'When a client deletes their account in the mobile app, their sign-in details are anonymised and the account is deactivated. Records a spa needs to keep, such as past bills, remain with that spa.',
                    ],
                ],
                [
                    'heading' => 'How we protect it',
                    'body' => [
                        [
                            'Passwords are hashed.',
                            'Connections to Servora are encrypted in production.',
                            'Access inside a business is limited by role and permission. What Servora administrators can do is limited by permission, and their actions are recorded in an audit log.',
                            'Administrators can protect their accounts with two-factor authentication.',
                            'Repeated sign-in and verification attempts are rate-limited.',
                        ],
                        'No system is completely secure. If a breach affects your personal information, we will notify you and the National Privacy Commission as the law requires.',
                    ],
                ],
                [
                    'heading' => 'Your rights',
                    'body' => [
                        'Under the Data Privacy Act you have the right to be informed, to access your personal information, to correct it, to object to or withdraw consent for its processing, to have it erased or blocked where the law allows, to receive a copy in a portable format, and to complain to the National Privacy Commission.',
                        'You can see and change most of your details in your profile. For anything else, write to servoraofcl@gmail.com. If your information was entered by a spa (for example as its client or staff member), we may refer your request to that spa, which decides how its records are kept.',
                    ],
                ],
                [
                    'heading' => 'Children',
                    'body' => [
                        'Servora is not intended for children. We do not knowingly collect personal information from anyone under 18.',
                    ],
                ],
                [
                    'heading' => 'Cookies and device storage',
                    'body' => [
                        'The web app uses a cookie to keep you signed in and small amounts of browser storage to remember preferences such as theme and table view. The mobile app stores your sign-in and preferences on your device. We do not use advertising cookies.',
                    ],
                ],
                [
                    'heading' => 'Changes to this policy',
                    'body' => [
                        'We may update this policy. The date at the top shows when it last changed. If a change is significant, we will tell you in the app or by email.',
                    ],
                ],
                [
                    'heading' => 'Contact Us',
                    'body' => [
                        'If you have questions, concerns, or requests regarding this Privacy Policy or the processing of your personal information, please contact:',
                        'SERVORA',
                        'Spa Management Platform',
                        'Email: servoraofcl@gmail.com',
                    ],
                ],
            ],
        ],
    ],
];
