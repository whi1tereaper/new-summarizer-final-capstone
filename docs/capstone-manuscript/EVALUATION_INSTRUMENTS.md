# Appendix H. Proposed Technical Evaluation Instrument

## Instrument Status and Purpose

PROPOSED DRAFT — NOT ADMINISTERED; ADVISER AND INSTITUTIONAL REVIEW REQUIRED.

Project: **LIGHT summarizer**.

Researchers: Robert Martin Baltisoto, Shielo Mariell Leodones, Gieselle Sabbaluca and Sofiah Heaven Galman.

This original instrument asks qualified technical reviewers to assess observed application behavior under agreed staged tasks. It contains 28 items across seven selected domains from the historical reference template. Maintainability is not included. It is not a reproduced ISO questionnaire, a complete assessment of ISO/IEC 25010:2011 or 25010:2023, or a security certification. The older edition's withdrawn status is acknowledged in the References. Domain selection and item wording require approval before use.

Study code: [assigned code]. Date: [actual date]. Instrument version: [approved version]. Application revision/engine: [record]. Reviewer expertise category: [approved nonidentifying category]. Browser/device and environment: [record]. Tasks/evidence provided: [record]. Participant-information and consent reference: [record; no consent inferred by this blank form].

## Directions and Response Scale

Complete the assigned tasks or inspect the designated evidence before answering. Select one response for each item. Base the response on what was observed, and add a brief example for a disagreement or unavailable feature. An observed failure is evidence for disagreement; it is not “not applicable.” Choose N/A only when an item genuinely does not apply or the required evidence was not available, and state the reason. Do not test other people's accounts or private records.

Table 61. Response scale for the proposed technical and end-user questionnaires.

| Code | Response | Direction |
|---|---|---|
| 1 | Strongly Disagree | The statement clearly does not describe the observed evidence or experience. |
| 2 | Disagree | The statement generally does not describe the observed evidence or experience. |
| 3 | Agree | The statement generally describes the observed evidence or experience. |
| 4 | Strongly Agree | The statement clearly describes the observed evidence or experience. |
| N/A | Not observed / Not applicable | No numeric score; explain the missing exposure or inapplicability. |

Table 61 defines the response convention for both proposed questionnaires. N/A remains outside the numeric scale so missing exposure is not mistaken for a neutral judgment. Proposed exposure includes text and supported-file submission, a configured URL example, preference changes, result/history access, a controlled invalid input, and available supporting actions. Technical reviewers may inspect staging evidence for access denial, job recovery, installation and configuration. Unavailable models or services must be recorded, not presented as successful demonstrations. The known authorization defects require correction or an institutionally reviewed restricted environment before any real-user trial.

## Functional Suitability

Table 62. Proposed technical functional-suitability items.

| Item | Statement to be assessed | Response |
|---|---|---|
| T-FS1 | The tested text-input workflow produces an output that can be traced to the submitted source. | [1/2/3/4/N/A] |
| T-FS2 | The tested PDF/DOCX or URL pathway performs the documented input-handling task for the supplied example. | [1/2/3/4/N/A] |
| T-FS3 | Changes to supported profile, depth and format settings are reflected appropriately in the tested output. | [1/2/3/4/N/A] |
| T-FS4 | Tested result, history and feedback actions perform their documented functions without losing the relevant record association. | [1/2/3/4/N/A] |

Table 62 connects functional suitability to actual input, preference and record-handling tasks. It requires a traceable example instead of assuming that the presence of a control establishes successful behavior.

Evidence/example and N/A reason: [reviewer entry]. Assess source fidelity using the supplied original; do not infer accuracy from fluent wording alone.

## Performance Efficiency

Table 63. Proposed technical performance-efficiency items.

| Item | Statement to be assessed | Response |
|---|---|---|
| T-PE1 | Processing time for the stated input size is acceptable against the documented evaluation task target. | [1/2/3/4/N/A] |
| T-PE2 | The interface provides timely progress or completion information while the tested request is processed. | [1/2/3/4/N/A] |
| T-PE3 | Observed resource use remains within the stated evaluation-environment limits during the tested workload. | [1/2/3/4/N/A] |
| T-PE4 | Timeout or cancellation behavior bounds the tested operation according to the documented task conditions. | [1/2/3/4/N/A] |

Table 63 asks reviewers to judge performance against stated task conditions and available measurements. It distinguishes responsiveness and bounded operation from an unsupported general claim of speed.

Evidence/example and N/A reason: [reviewer entry]. Record hardware, source size, timing target and actual measurement separately. If no target or resource evidence was provided, mark the relevant item N/A rather than inventing a benchmark.

## Compatibility

Table 64. Proposed technical compatibility items.

| Item | Statement to be assessed | Response |
|---|---|---|
| T-CO1 | The tested browser displays the required controls and outputs without preventing the assigned tasks. | [1/2/3/4/N/A] |
| T-CO2 | The tested file/input format is interpreted consistently with its documented support and limitations. | [1/2/3/4/N/A] |
| T-CO3 | The configured PHP-to-Python integration exchanges the required request and response information in the staged test. | [1/2/3/4/N/A] |
| T-CO4 | The tested external or supporting service handles its documented interface without disrupting unrelated core tasks. | [1/2/3/4/N/A] |

Table 64 covers the particular browser, input format and service interfaces included in the staged evaluation. Conclusions must remain limited to those tested combinations.

Evidence/example and N/A reason: [reviewer entry]. Record tested browsers, formats and service configuration; one environment does not demonstrate universal compatibility.

## Usability

Table 65. Proposed technical usability items.

| Item | Statement to be assessed | Response |
|---|---|---|
| T-US1 | Labels and instructions make the tested input and summary-setting choices understandable. | [1/2/3/4/N/A] |
| T-US2 | Keyboard operation and focus behavior allow completion of the assigned navigation tasks. | [1/2/3/4/N/A] |
| T-US3 | Error messages identify the tested problem and provide an appropriate next action. | [1/2/3/4/N/A] |
| T-US4 | The result layout distinguishes source information, generated output and supporting actions clearly. | [1/2/3/4/N/A] |

Table 65 focuses on comprehensible controls, keyboard tasks, error guidance and result presentation. These items provide a bounded usability review rather than a complete accessibility audit.

Evidence/example and N/A reason: [reviewer entry]. This short domain does not constitute a comprehensive accessibility-conformance assessment.

## Reliability

Table 66. Proposed technical reliability items.

| Item | Statement to be assessed | Response |
|---|---|---|
| T-RE1 | Repeated execution of the assigned task completes without unexplained loss of state under the recorded conditions. | [1/2/3/4/N/A] |
| T-RE2 | Invalid input produces a controlled failure without exposing unrelated data or damaging the tested session. | [1/2/3/4/N/A] |
| T-RE3 | The staged retry/cancel scenario maintains a consistent job outcome without unintended duplicate records. | [1/2/3/4/N/A] |
| T-RE4 | The supplied recovery demonstration restores the selected data and critical functions as documented. | [1/2/3/4/N/A] |

Table 66 requires evidence of repeated operation, controlled failure, job consistency and demonstrated recovery. The recovery item cannot be answered favorably from the existence of a maintenance recommendation alone.

Evidence/example and N/A reason: [reviewer entry]. No recovery demonstration has been supplied by this manuscript; T-RE4 remains N/A until one is actually performed.

## Security

Table 67. Proposed technical security items.

| Item | Statement to be assessed | Response |
|---|---|---|
| T-SE1 | The staged ownership tests deny a synthetic user access to another user's private records. | [1/2/3/4/N/A] |
| T-SE2 | The tested account-security actions enforce the documented authentication and session controls. | [1/2/3/4/N/A] |
| T-SE3 | The tested state-changing requests reject missing or invalid authorization/CSRF requirements. | [1/2/3/4/N/A] |
| T-SE4 | The reviewed logs, errors and exported material avoid exposing the test secrets or prohibited identifying information. | [1/2/3/4/N/A] |

Table 67 associates security judgments with controlled access, account, mutation and disclosure checks. It does not invite reviewers to infer security from appearance or to test private production data.

Evidence/example and N/A reason: [reviewer entry]. Use synthetic records and explicit test authorization. A favorable perception cannot override a reproduced defect. The documented document-API ownership failure must remain visible in the assessment evidence.

## Portability

Table 68. Proposed technical portability items.

| Item | Statement to be assessed | Response |
|---|---|---|
| T-PO1 | The documented runtime and dependency requirements identify what the staged installation actually needs. | [1/2/3/4/N/A] |
| T-PO2 | A clean installation in the designated staging environment completes the documented critical checks. | [1/2/3/4/N/A] |
| T-PO3 | Configuration allows the tested deployment paths and service settings to be changed without editing unrelated logic. | [1/2/3/4/N/A] |
| T-PO4 | Moving the selected test data and configuration to the designated second environment preserves the assigned functions. | [1/2/3/4/N/A] |

Table 68 distinguishes stated requirements from an actual clean installation or transfer. A second-environment claim requires a real staged demonstration and cannot be inferred from configurable paths alone.

Evidence/example and N/A reason: [reviewer entry]. Do not rate installation or transfer as successful from documentation alone. The fresh-install password-reset schema discrepancy is a relevant unresolved finding.

## Reviewer Comments and Researcher Coding

Most significant observed strength: [optional response].

Most significant observed problem and reproduction: [optional response].

Priority improvement: [optional response].

Unavailable task/evidence and reason: [response where applicable].

Researcher use only: valid item counts [ ]; N/A counts and reasons [ ]; protocol deviations [ ]; approved inclusion/exclusion decision [ ]. Preserve original answers; do not replace disagreement with missing values. Chapter II defines the proposed analysis convention. No responses or computed scores are populated in this document.

# Appendix I. Proposed End-User Evaluation Instrument

## Instrument Status and Directions

PROPOSED DRAFT — NOT ADMINISTERED; REQUIRES APPROVED PARTICIPANT INFORMATION, CONSENT AND TASKS.

Project: **LIGHT summarizer**. Study code: [assigned code]. Date: [actual date]. Instrument version: [approved version]. Device/browser: [record]. Tasks attempted and help received: [record].

After completing the assigned activities, select 1 (Strongly Disagree), 2 (Disagree), 3 (Agree), 4 (Strongly Agree), or N/A (not observed/not applicable) for each statement. N/A has no numeric score. Give an example when something did not work or was unclear. There are no preferred answers, and declining participation must not affect grades or ordinary services. The final institution-approved participation notice should provide the actual contact, withdrawal conditions, data use and retention details before the instrument is used.

Use only the prepared or otherwise approved reading material. Compare important claims with the original article. Do not upload confidential personal information. The tasks should record which functions were available; a feature shown in a screenshot is not necessarily a completed task.

## Functional Suitability

Table 69. Proposed end-user functional-suitability items.

| Item | Statement to be assessed | Response |
|---|---|---|
| U-FS1 | I could submit the assigned article using the input method provided in the task. | [1/2/3/4/N/A] |
| U-FS2 | The summary helped me identify the article's main ideas during the assigned review. | [1/2/3/4/N/A] |
| U-FS3 | The available length and presentation choices suited the assigned reading task. | [1/2/3/4/N/A] |
| U-FS4 | I could use the assigned supporting action, such as history, nutshell, translation or audio, for its stated purpose. | [1/2/3/4/N/A] |

Table 69 relates end-user functional judgments to the assigned article and the supporting action actually attempted. The responses describe perceived usefulness in that task, not a measured improvement in academic achievement.

Example, difficulty or N/A reason: [participant response]. Identify the supporting action actually attempted. This domain records experience, not an objective test of learning or factual accuracy.

## Perceived Performance Efficiency

Table 70. Proposed end-user performance items.

| Item | Statement to be assessed | Response |
|---|---|---|
| U-PE1 | The waiting time for the assigned summary was acceptable for that task. | [1/2/3/4/N/A] |
| U-PE2 | The application showed enough progress information while I waited. | [1/2/3/4/N/A] |
| U-PE3 | The tested buttons and page changes responded promptly during my use. | [1/2/3/4/N/A] |
| U-PE4 | I could continue the assigned reading activity without unnecessary repeated waiting or resubmission. | [1/2/3/4/N/A] |

Table 70 captures the participant's experience of waiting, progress feedback and repeated actions. Interpret it alongside the recorded device and network conditions rather than treating the ratings as elapsed-time measurements.

Example, difficulty or N/A reason: [participant response]. These are perceptions under the recorded device/network conditions; they are not measured processing-speed benchmarks.

## Usability

Table 71. Proposed end-user usability items.

| Item | Statement to be assessed | Response |
|---|---|---|
| U-US1 | I understood where to enter an article and start the assigned task. | [1/2/3/4/N/A] |
| U-US2 | The labels and summary-setting explanations were understandable to me. | [1/2/3/4/N/A] |
| U-US3 | The result text and controls were easy to read and locate on my device. | [1/2/3/4/N/A] |
| U-US4 | When I needed to change an input or respond to a message, I understood what to do next. | [1/2/3/4/N/A] |

Table 71 covers starting the task, understanding settings, reading results and responding to messages. It permits specific interface difficulties to be recorded without assuming that every participant used every feature.

Example, difficulty or N/A reason: [participant response].

Most useful part of the assigned task: [optional response].

Part that was confusing or did not work: [optional response].

Suggested improvement: [optional response].

Researcher use only: actual task exposure [ ]; response validation [ ]; N/A explanation [ ]; protocol deviations [ ]. All fields remain blank until genuine collection. The 12 items use the same four-point convention as Appendix H; ordinary application star ratings and the separate five-point summary-review rubric must remain separate datasets.
