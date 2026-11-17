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
            'revision_date' => '2026-11-14',
            'intro' => 'SERVORA is a spa management platform for spa businesses, their staff and their clients. These Terms cover the SERVORA web app, mobile app and related services. "SERVORA", "we", "us" and "our" mean the team operating the platform. Creating an account means accepting these Terms and the Privacy Policy.',
            'summary' => [
                'SERVORA provides the software. Each spa provides its treatments and sets its own services, prices and booking policies.',
                'Clients pay the spa directly. SERVORA records those payments and receipts. A spa\'s subscription payments to SERVORA are separate.',
                'Business features depend on the subscription or trial, the plan, account permissions and any restrictions. A public marketplace listing needs verification.',
                'Clients can change eligible bookings within the spa\'s rules, before check-in and before the scheduled start time.',
                'Each spa sets up its rewards within SERVORA\'s program rules and available features.',
                'Deleting a client account anonymizes and deactivates the login. Spa visit and payment records may remain. For questions, contact servoraofcl@gmail.com.',
            ],
            'sections' => [
                [
                    'heading' => 'What SERVORA is',
                    'body' => [
                        'SERVORA puts spa reservations and daily management on one shared record. Owners and authorized staff use the web app to manage branches, services, facilities, staff, appointments, walk-in queues, billing, customer programs and reports, according to their access.',
                        'Platform administrators manage subscription plans, review owner, business and branch verification, and manage marketplace visibility.',
                        'Clients use the mobile app to find spa listings by map and search, see service and therapist availability, book, follow the queue, and view receipts, notifications and rewards. Services may include visits at the spa and home service.',
                        'SERVORA provides the software. Each spa provides its treatments and manages its service information, prices, staff and operating policies.',
                    ],
                ],
                [
                    'heading' => 'Your account',
                    'body' => [
                        [
                            'Give accurate account information and keep it up to date.',
                            // Existing eligibility policy; age enforcement remains a separate review item.
                            'You must be at least 18 years old to create an account.',
                            'Email-and-password sign-up needs email verification before sign-in. Google sign-in uses the email Google has verified. Extra sign-in verification applies where it is turned on.',
                            'Keep your password and sign-in details private. If you suspect unauthorized access, contact servoraofcl@gmail.com.',
                            'Do not share a login. Owners can create separate manager and front-desk accounts, within their plan\'s capacity and permissions. Therapist records and assignments are managed separately from these logins.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Spa owners: verification',
                    'audience' => 'owner',
                    'body' => [
                        'Business setup, verification and subscription access are separate. Business features depend on subscription or trial access, account permissions and any restrictions. Onboarding may ask a new owner to complete verification before opening plans or other modules.',
                        'A public marketplace listing needs verification of the owner\'s identity, the business and the branch, plus the required plan access and a ready listing. Access to internal features does not by itself make a business publicly listed.',
                        'Owner verification uses a government-issued ID and face images captured in the verification flow. Business documents depend on the business type: DTI for a sole proprietorship, SEC for a corporation or partnership. Branch verification needs the mayor\'s permit.',
                        'Submit accurate, genuine and current information and documents. A submission may be approved, rejected or returned for resubmission. Owners manage the staff accounts and permissions within their business.',
                    ],
                ],
                [
                    'heading' => 'Spa owners: plans and payment',
                    'audience' => 'owner',
                    'body' => [
                        [
                            'Business features need an active paid subscription, an active free trial or an applicable paid-subscription grace period. Access also depends on the plan, account permissions and any business or branch restrictions.',
                            'Plans set the included features and capacity, including branches and manager/front-desk logins. Current plans, prices, capacity options and billing choices are shown in SERVORA.',
                            'Paid plans are billed monthly or yearly where offered, in Philippine pesos, through the platform\'s payment provider. A paid term or upgrade starts once payment is confirmed.',
                            'Where available, an eligible owner may choose to start a free trial. It does not start automatically at registration, and its length is shown before it starts. Eligibility checks include earlier trial use by the owner and earlier subscriptions of the business.',
                            'A free trial is never charged automatically and has no grace period after it ends. Buying a paid plan ends the trial at once; unused trial days are not added to the paid term.',
                            'Automatic renewal can be turned on with an eligible saved payment method. GCash checkout is one-time only. Cards and Maya can be saved for reuse in the current checkout.',
                            'With automatic renewal on, SERVORA attempts payment when the subscription is due and may retry failed attempts during the grace period. Renewal depends on a valid payment method, plan availability and capacity requirements. Suspended businesses are not charged automatic renewals.',
                            'Turning automatic renewal off stops future attempts. It does not end the current paid term at once or issue a refund by itself.',
                            'Where plan changes are enabled, an owner may request one while the paid term is active. An eligible upgrade applies after payment of the displayed price difference is confirmed, and keeps the existing billing cycle and due date. There is no separate short window after purchase for requesting a change.',
                            'Downgrades and billing-cycle changes are scheduled for the next term and may be changed or withdrawn before the current term ends. The next term begins only after its payment is confirmed.',
                            'A smaller plan may require the owner to choose which branches and staff logins to keep. The others may be put on hold rather than deleted. Included features may also change with the new plan.',
                            'Paid subscriptions may get a configured grace period after expiry. Trials, and subscriptions ended by declining updated plan terms, do not. When access ends, the owner can arrange continued access from the subscription and payment screens.',
                            'Owners may accept or decline a notified replacement plan. Declining lets the current term end at expiry without automatic renewal into the replacement. The existing term is not replaced immediately by the new plan terms.',
                            'SERVORA has no automated subscription-refund requests and no self-service way to cancel a paid term immediately. For subscription payment concerns or refund requests, contact servoraofcl@gmail.com. This describes the available features and does not exclude any rights available under applicable law.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Clients: bookings and visits',
                    'audience' => 'client',
                    'body' => [
                        [
                            'Each spa manages its services, prices, opening hours and booking policies. Booking choices depend on service, therapist and branch availability.',
                            'Clients can cancel or reschedule an eligible reservation in the app only while it is still scheduled, before check-in and before its start time, and within the spa\'s change window and rescheduling limits. When the app cannot make the change, contact the spa.',
                            'Authorized spa staff may update or cancel bookings. Changes are recorded in SERVORA, and notifications are sent through the applicable notification features.',
                            'Repeated missed bookings may pause further app bookings under the spa\'s booking policy. Contact the spa if this applies to you.',
                            'Staff manage therapist schedules, availability and assignments. Queue progress follows check-in and service updates. Queue positions and waiting times are estimates and may change.',
                            'For home service, clients give the service address and a map pin, which the spa uses to arrange and provide the visit. Home-service charges appear in the booking.',
                            'Clients pay the spa directly, using the methods it accepts. SERVORA records the payments, receipts and adjustments that authorized spa staff enter. These are separate from the spa\'s subscription payments to SERVORA.',
                            'Raise questions about treatments, spa prices, booking arrangements or treatment refunds with the spa. SERVORA does not collect treatment payments from clients through the app.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Loyalty points, vouchers, discounts and memberships',
                    'audience' => 'client',
                    'body' => [
                        'Each spa sets up its customer programs within the options SERVORA offers. Eligibility, earning rules, redemption conditions, branch or booking-channel limits and expiry depend on the program.',
                        'Loyalty points are earned by eligible app-linked clients under the spa\'s rules. Vouchers, discounts and memberships may have their own eligibility and usage conditions. Rewards stay with the spa business that issued them.',
                        'Voiding, reopening or refunding a bill may reverse points earned from it or cancel unused rewards it generated. A refund may restore a voucher that was used on that bill.',
                        'Programs also depend on the business\'s settings and plan features. When those are unavailable, balances may stay stored while earning or redemption is unavailable. Expiry conditions still apply.',
                    ],
                ],
                [
                    'heading' => 'Listings and reviews',
                    'body' => [
                        'Marketplace visibility depends on verification, plan access, branch operating status and listing readiness. Each spa maintains its listing information, services, prices and images, and must have permission to use what it uploads.',
                        'Clients can review a spa through an eligible completed appointment. The system limits repeat ratings of the same branch by the same client.',
                        'Reviews should describe a real visit and must not be abusive, misleading or unrelated. To report a review, contact servoraofcl@gmail.com.',
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
                        'Spa businesses enter and manage staff, client, appointment and billing records in SERVORA. Access to them depends on business association, branch access, roles and permissions. Businesses should enter only information they are authorized to collect and use.',
                        'The Privacy Policy explains the personal information handled through the platform, how it is used and shared, and the privacy requests available. For privacy questions, contact servoraofcl@gmail.com.',
                    ],
                ],
                [
                    'heading' => 'Suspension and closing an account',
                    'body' => [
                        'Account, business or branch restrictions can limit sign-in, operations or marketplace availability. Subscription expiry and plan-capacity holds are separate restrictions. For questions about a restriction, contact servoraofcl@gmail.com.',
                        'Clients can delete their account in the mobile app after confirming their password. This anonymizes and deactivates the login, revokes its sign-in tokens and unlinks it from spa client records. Spa visit and payment records may remain; deleting an account does not erase all records a spa holds.',
                        'Owners who want to close a business account should contact servoraofcl@gmail.com. A closure request does not confirm that the business is closed or that automatic renewal is off. Automatic renewal is managed in the subscription settings.',
                    ],
                ],
                [
                    'heading' => 'Availability and changes to the service',
                    'body' => [
                        'The features available depend on the plan, permissions, business settings and current service availability. Booking availability and queue information change as staff and clients update records.',
                        'For a platform problem, contact servoraofcl@gmail.com. For an immediate question about a visit or booking, contact the spa.',
                    ],
                ],
                [
                    'heading' => 'SERVORA and spa responsibilities',
                    'body' => [
                        'SERVORA provides software for reservations, therapist and queue management, transaction recording, customer programs and business reporting. Each spa manages its staff, service delivery, prices, booking policies and the accuracy of the records it enters.',
                        'Send platform questions to servoraofcl@gmail.com, and questions about spa operations and treatments to the spa. This does not remove responsibilities imposed by applicable law.',
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
                        'The revision date at the top shows when these Terms were last updated. Review the current Terms when you use SERVORA. For questions about a change, contact servoraofcl@gmail.com.',
                    ],
                ],
                [
                    'heading' => 'Contact Us',
                    'body' => [
                        'For questions, concerns or requests about these Terms or the use of SERVORA, contact:',
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
            'revision_date' => '2026-11-14',
            'intro' => 'SERVORA is a spa management platform. This policy explains what personal information the SERVORA web app and mobile app collect, why, who it is shared with, and the choices you have. "We", "us" and "our" mean the team operating SERVORA. We handle personal information in line with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173).',
            'summary' => [
                'We do not sell personal information.',
                'A client\'s location is used only to show spas nearby, and only if allowed. It is not shared with spas.',
                'When a client books, the spa sees the client\'s name, phone number and booking details.',
                'Owners\' IDs, face scans and business documents are stored privately. Only the owner and the SERVORA administrators who review verifications can open them.',
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
                        'From spa businesses, as they use SERVORA:',
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
                            'To confirm that a spa business and its owner are real before it can operate on SERVORA.',
                            'To run the features you use: bookings, queues, billing, staff management, reports and customer programs.',
                            'To process subscription payments and send receipts and reminders.',
                            'To send service messages, such as verification emails, booking updates and security alerts.',
                            'To keep an audit trail of important actions, prevent fraud and abuse, and fix problems.',
                            'To meet legal obligations.',
                        ],
                        'We rely on your consent, on what is needed to provide the service you asked for, on our legitimate interest in keeping SERVORA safe and working, and on legal requirements, as the case may be.',
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
                        'IDs, face scans and business documents are used only to verify the owner and the business. They are stored privately and can be opened only by the owner who submitted them and by SERVORA administrators who review verifications.',
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
                            'Connections to SERVORA are encrypted in production.',
                            'Access inside a business is limited by role and permission. What SERVORA administrators can do is limited by permission, and their actions are recorded in an audit log.',
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
                        'SERVORA is not intended for children. We do not knowingly collect personal information from anyone under 18.',
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
                        'For questions, concerns or requests about this Privacy Policy or how your personal information is handled, contact:',
                        'SERVORA',
                        'Spa Management Platform',
                        'Email: servoraofcl@gmail.com',
                    ],
                ],
            ],
        ],
    ],
];
