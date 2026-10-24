"""Fixed engineering-authored corpus; no independent human labels are claimed.

Fresh held-out examples were written before parameter search. The historical
benchmark is explicitly distinct because its omissions have already been read.
"""
from copy import deepcopy
from evaluation.article_engine_experiment import quality_documents
from evaluation.article_routing_fixtures import DEVELOPMENT


def units(*rows):
    return [{"id": name, "all_of": tokens} for name, *tokens in rows]


DEV_ANNOTATIONS = [
    units(("main", "announced", "evening bus"), ("scope", "three routes", "six month"),
          ("evaluation", "findings", "monthly"), ("caveat", "not promised", "uncertain"),
          ("decision", "operating costs", "evidence")),
    units(("main", "ventilation", "concentration"), ("sample", "twenty schools"),
          ("method", "Regression", "temperature"), ("result", "small association"),
          ("caveat", "cannot establish", "caused")),
    units(("main", "protocol", "ciphertext"), ("mechanism", "key exchange"),
          ("rotation", "rotates keys"), ("endpoint", "compromised screen"),
          ("limit", "Timing", "message size")),
    units(("main", "cities should protect", "parks"), ("opposition", "Critics", "expensive"),
          ("costs", "maintenance costs", "evidence"), ("voice", "opportunity", "challenge")),
]


def development():
    rows = [deepcopy(d) for d in quality_documents()[:5]]
    for i in range(4):
        d = DEVELOPMENT[i]
        rows.append({"id": d["id"], "source_text": d["text"], "category": "development",
                     "profile": "general", "provenance": "engineering_authored_synthetic",
                     "key_units": DEV_ANNOTATIONS[i], "critical_facts": []})
    return rows


def heldout():
    rows = [
        {"id": "CE-HO-NEWS", "category": "news", "profile": "news", "source_text": """Rail service suspended after a hillside inspection

Regional officials suspended the North Valley rail service on Tuesday after inspectors found movement in a retaining wall near the hillside track. The closure affects the line between Alder Junction and the coastal terminal, according to the transport authority. Engineers said the decision was precautionary and did not mean that the entire railway was unsafe.

The service normally carries 8,400 passengers each weekday. The authority announced replacement buses, but warned that the longer road journey would add about 35 minutes to most trips. Freight operators will use a separate inland route while the passenger section remains closed.

Inspectors measured a 6 millimeter change in the wall during the latest survey. Earlier inspections had not identified significant movement, and engineers have not established whether recent rainfall caused the change. An independent assessment is expected within ten days.

The authority said there is no confirmed reopening date. Repairs could cost $3.2 million, although that estimate may change after the independent assessment. Officials promised to publish the findings before deciding whether normal services can resume.""",
         "key_units": units(("main", "suspended", "retaining wall"), ("passengers", "8,400"),
                            ("delay", "35 minutes"), ("measurement", "6 millimeter"),
                            ("uncertainty", "not established", "rainfall"), ("reopen", "no confirmed", "date"),
                            ("cost", "$3.2 million", "may change")),
         "critical_facts": ["Tuesday", "8,400", "35 minutes", "6 millimeter", "ten days", "$3.2 million"],
         "focus": {"query": "passenger disruption", "units": ["passengers", "delay"], "evidence": ["8,400", "35 minutes"]}},
        {"id": "CE-HO-RESEARCH", "category": "research", "profile": "academic", "source_text": """Trial compares two reminder systems in adult education

Researchers conducted a randomized trial to determine whether weekly text reminders improved attendance in adult education classes. The trial enrolled 520 participants across eight colleges and assigned them to either reminder messages or the existing printed timetable. Participants were followed for sixteen weeks.

Attendance records showed a 9 percent increase in the reminder group compared with the timetable group. However, the difference was not statistically significant after accounting for differences between colleges. The investigators did not claim that reminder messages caused a reliable improvement in attendance.

Both groups completed the same end of course assessment. The study found no significant difference in test scores, although participants receiving messages said they found scheduling easier. These self-reported responses were collected after the attendance period ended.

The trial excluded learners without mobile phones, so the findings may not generalize to all adult learners. The investigators recommended a larger trial before institutions replace printed timetables. They also noted that communication costs were not measured in this study.""",
         "key_units": units(("main", "randomized trial", "attendance"), ("sample", "520", "eight colleges"),
                            ("followup", "sixteen weeks"), ("effect", "9 percent"),
                            ("negative", "not statistically significant"), ("scores", "no significant difference", "test scores"),
                            ("limit", "excluded", "mobile phones"), ("recommendation", "larger trial")),
         "critical_facts": ["520", "eight colleges", "sixteen weeks", "9 percent", "not statistically significant", "no significant difference"],
         "focus": {"query": "study design", "units": ["main", "sample", "followup"], "evidence": ["randomized", "520", "sixteen"]}},
        {"id": "CE-HO-TECH", "category": "technical", "profile": "technical", "source_text": """A cache redesign for frequently updated inventory

An inventory service introduced a versioned cache to reduce repeated database reads without showing customers outdated stock information. Each cache entry contains the product identifier, a version number, and an expiry time. The design replaces a cache that relied only on a fixed refresh interval.

In a load test, the new cache reduced database reads by 42 percent while serving 1,600 requests per second. A separate failure test found 73 stale responses when invalidation messages were delayed. The team therefore kept a database verification step for checkout requests, even though that step adds about 8 milliseconds of latency.

Version numbers let the service reject an older update that arrives after a newer update. Workers compare the incoming version with the stored version before changing the cached value. Repeated messages can be processed safely because an update with an unchanged version does not change inventory twice.

The system has not been tested above 1,600 requests per second. If the message broker becomes unavailable, expiry times limit how long ordinary product pages can display stale information. Checkout continues to use database verification until independent testing establishes a safe alternative.""",
         "key_units": units(("main", "versioned cache", "outdated"), ("reads", "42 percent"),
                            ("rate", "1,600 requests"), ("failure", "73 stale"),
                            ("verification", "database verification", "8 milliseconds"),
                            ("ordering", "reject", "older update"), ("limit", "not been tested")),
         "critical_facts": ["42 percent", "1,600", "73", "8 milliseconds", "not been tested"],
         "focus": {"query": "failure risks", "units": ["failure", "limit"], "evidence": ["failure", "stale", "not been tested", "unavailable"]}},
        {"id": "CE-HO-OPINION", "category": "opinion", "profile": "general", "source_text": """Why public libraries should keep basic services free

I argue that public libraries should keep basic borrowing and study spaces free because access should not depend on household income. Supporters of new membership fees say that extra revenue would help libraries buy books and extend opening hours. That argument recognizes a real funding problem but does not explain why readers with the least money should pay first.

Critics of free access believe that people value a service more when they contribute directly to its costs. Some library volunteers disagree and say that an entrance fee could discourage families who already feel unwelcome in public institutions. Neither perspective establishes how many readers would stop using a particular library.

Councils should publish operating costs and consult residents before introducing any charge. A voluntary donation system may raise less predictable revenue, but it could preserve access while allowing supporters to contribute. Libraries should test that option before assuming that membership fees are the only practical solution.

The central question is how a library serves its community, rather than whether every visit produces income. Protecting basic access does not prevent charging for optional commercial services. It means that the shared purpose of a public library should guide the funding debate.""",
         "key_units": units(("main", "I argue", "free", "income"), ("opposition", "fees", "revenue"),
                            ("uncertainty", "Neither", "how many"), ("proposal", "voluntary donation", "access"),
                            ("consultation", "consult residents"), ("scope", "optional commercial services")),
         "critical_facts": [],
         "focus": {"query": "membership fees", "units": ["opposition", "proposal"], "evidence": ["fees", "donation"]}},
        {"id": "CE-HO-SCIENCE", "category": "educational", "profile": "scientific", "source_text": """How coastal wetlands reduce storm damage

Coastal wetlands reduce storm damage by slowing water and absorbing part of a wave's energy before it reaches buildings. Plant stems create friction, while roots help hold sediment in place. This mechanism is one reason scientists study wetlands alongside engineered flood barriers.

A field survey measured water levels at 36 locations during three storms. Sites with wider vegetation belts experienced waves that were about 18 percent lower than waves at nearby exposed sites. The survey did not establish that vegetation alone explained the difference because seabed shape also varied between locations.

Wetlands also provide nursery habitat for fish and resting areas for migrating birds. Those ecological benefits can support local fisheries even when no major storm occurs. However, pollution and interrupted sediment supply may reduce the ability of a wetland to maintain its elevation as sea levels rise.

Restoration therefore requires attention to water quality and sediment movement, not simply planting more stems. Scientists caution that a narrow strip of plants cannot guarantee protection from every storm. Communities should combine restoration with evacuation planning and suitable engineering where risks remain high.""",
         "key_units": units(("main", "wetlands", "slowing water"), ("sample", "36 locations", "three storms"),
                            ("result", "18 percent"), ("caveat", "did not establish", "seabed"),
                            ("ecology", "fish", "birds"), ("threat", "pollution", "sediment"),
                            ("limit", "cannot guarantee")),
         "critical_facts": ["36 locations", "three storms", "18 percent", "did not establish", "cannot guarantee"],
         "focus": {"query": "environmental threats", "units": ["threat"], "evidence": ["pollution", "sediment", "sea levels"]}},
        {"id": "CE-HO-BUSINESS", "category": "business", "profile": "executive", "source_text": """Factory revenue rises while production falls

An engineering manufacturer reported higher quarterly revenue despite producing fewer machines as customers shifted toward more expensive models. Revenue increased by 7 percent to $84 million, according to the company statement. The number of completed machines fell by 11 percent during the same quarter.

Management attributed the production decline partly to delays in imported components. Operating profit fell to $2.8 million from $4.1 million because overtime and shipping costs increased. The company said that higher selling prices had not fully offset those additional expenses.

Investors welcomed a new supply agreement but questioned how quickly component deliveries would improve. The agreement covers a twelve month period and does not guarantee a fixed delivery volume. Analysts said it was too early to conclude that the production problems had been resolved.

The manufacturer expects production to recover gradually, provided that delivery schedules become more reliable. It has not issued a new profit forecast. Management plans to review staffing after the next quarter rather than immediately reduce the workforce.""",
         "key_units": units(("main", "higher quarterly revenue", "fewer machines"), ("revenue", "7 percent", "$84 million"),
                            ("output", "11 percent"), ("profit", "$2.8 million", "$4.1 million"),
                            ("supply", "does not guarantee", "volume"), ("uncertainty", "too early", "resolved"),
                            ("forecast", "not issued", "profit forecast")),
         "critical_facts": ["7 percent", "$84 million", "11 percent", "$2.8 million", "$4.1 million", "twelve month", "not issued"],
         "focus": {"query": "financial results", "units": ["revenue", "output", "profit"], "evidence": ["revenue", "percent", "profit"]}},
    ]
    for d in rows:
        d["provenance"] = "fresh_engineering_authored_synthetic_not_human_verified"
        d["split"] = "heldout"
    return rows
