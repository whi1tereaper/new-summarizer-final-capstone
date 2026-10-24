"""Engineering-authored synthetic fixtures, not authentic published articles.

Development examples calibrate the rule score. Held-out examples and existing
frozen corpus entries measure behavior; they must not tune the threshold.
"""
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parent

DEVELOPMENT = [
    {"id": "dev-news", "article": True, "text": """City tests a new evening bus service

City officials announced an evening bus service on Monday after residents requested safer journeys home. The transport department said three routes would operate until midnight during a six month pilot. Officials reported that passengers currently wait more than forty minutes after the last regular service ends.

The council will fund the pilot from its existing transport budget rather than raise ticket prices. Independent observers will count passengers at each stop and report their findings monthly. The department has not promised to extend the service permanently because future funding remains uncertain.

Residents welcomed the announcement but said several industrial neighborhoods still lacked reliable connections. The mayor said the council would review those concerns after the first passenger survey. A final decision will depend on operating costs and evidence collected throughout the pilot."""},
    {"id": "dev-research", "article": True, "text": """Study examines classroom ventilation

Abstract
Researchers examined whether classroom ventilation was associated with student concentration across twenty schools. The study compared afternoon test scores with carbon dioxide measurements collected over twelve weeks. The sample included both rural and urban classrooms so that researchers could examine different building conditions.

Methods
Participants completed the same concentration exercise while technicians measured indoor air quality. Regression models adjusted for classroom size and outside temperature rather than assuming that ventilation explained every difference. Teachers did not know which measurements would be compared with their pupils' scores.

Results
The findings suggested a small association between improved ventilation and higher scores. However, the observational study cannot establish that ventilation caused the improvement. Researchers recommended a randomized experiment before schools make costly changes based only on these results."""},
    {"id": "dev-technical", "article": True, "text": """How encrypted messaging protects conversations

An encrypted messaging protocol protects messages by converting readable content into ciphertext before transmission. The software uses a key exchange algorithm so that participants can establish shared secrets without publishing those secrets. This architecture limits what an intermediary can read while the message travels across a network.

The protocol also rotates keys to reduce the damage caused by a later compromise. A database of encrypted messages should therefore remain difficult to read even if one older key becomes available. Engineers must still protect the devices at either end because encryption cannot secure a conversation displayed on a compromised screen.

The mechanism does not hide every kind of information from network observers. Timing and message size may still reveal patterns of communication between users. Independent security reviews remain necessary because a strong algorithm cannot compensate for errors in software implementation."""},
    {"id": "dev-opinion", "article": True, "text": """Why communities should protect small public parks

In my view, cities should protect small public parks before selling public land for commercial development. Supporters of redevelopment often argue that shops generate more revenue than open space. That argument overlooks the residents who rely on neighborhood parks for exercise, conversation, and relief from crowded housing.

Critics of park protection say that maintaining small sites can be expensive for local councils. They believe the money could support larger facilities serving more people across the city. This concern deserves attention, but it does not establish that every small park has little value.

I argue that councils should publish maintenance costs alongside evidence of how each park is used. Residents should have an opportunity to challenge redevelopment proposals before irreversible decisions are made. Protecting public space requires a practical debate about access rather than a simple assumption that financial returns measure every benefit."""},
    {"id": "dev-invoice", "article": False, "text": "Invoice\nNumber: 204\nCustomer: Example school\nBooks | 20 | 400\nTax: 48\nTotal: 448\nPayment due in thirty days."},
    {"id": "dev-resume", "article": False, "text": "Resume\nAlex Example\nEducation: Computer Science\nExperience: Software developer\nSkills: Python, PHP, NLP\nReferences available on request."},
    {"id": "dev-code", "article": False, "text": "import json\ndef summarize(text):\n    result = text.split()\n    return json.dumps(result)\nprint(summarize('hello'))"},
    {"id": "dev-list", "article": False, "text": "Shopping list\n- Apples\n- Rice\n- Soap\n- Tea\n- Milk\n- Paper"},
    {"id": "dev-form", "article": False, "text": "Application form\nName: ______\nDate: ______\nAddress: ______\nSignature: ______"},
    {"id": "dev-ambiguous", "article": False, "text": "Officials said a survey might begin next week. The funding decision has not been made."},
    {"id": "dev-report", "article": False, "text": "Executive Summary\n" + "The management team reviewed annual revenue and staffing costs. The board will decide the final allocation after reviewing the figures. The department said the forecast remains uncertain.\n\n" * 8},
]
# Input-format augmentation of DEVELOPMENT only: existing PDF extraction can
# flatten line/paragraph boundaries. These are not independent article samples.
DEVELOPMENT += [{"id": d["id"] + "-flattened", "article": True,
                 "text": " ".join(d["text"].split())}
                for d in DEVELOPMENT[:4]]


def heldout():
    corpus = json.loads((ROOT / "corpus" / "heldout_documents.json").read_text(encoding="utf-8"))
    positive = {"research", "news", "technical", "essay", "contradictory"}
    rows = [{"id": d["id"], "article": d["category"] in positive,
             "text": d["source_text"], "provenance": "existing_engineering_synthetic"}
            for d in corpus["documents"]]
    rows += [
        {"id": "heldout-receipt", "article": False, "text": "Receipt\nMarket Road Shop\nBread | 2 | 60\nMilk | 1 | 90\nTotal 150\nCash received 200\nChange 50"},
        {"id": "heldout-cv", "article": False, "text": "Curriculum Vitae\nJamie Example\nWork history\nResearch assistant 2024\nEducation\nBachelor of science\nContact details supplied separately."},
        {"id": "heldout-code", "article": False, "text": "<?php\nfunction process($request) {\n return json_encode($request);\n}\n$config = ['debug' => false];"},
        {"id": "heldout-config", "article": False, "text": "Configuration\nDEBUG=false\nPORT=9000\nWORKERS=3\nTIMEOUT=60\nENGINE=legacy"},
        {"id": "heldout-short", "article": False, "text": "The experiment measured rainfall. Researchers may publish the findings after review."},
        {"id": "heldout-educational", "article": True, "text": """Understanding how rivers shape the land

A river transports sediment as water flows from higher ground toward the sea. Erosion means that moving water removes soil and rock from the river channel. This process explains why a river can gradually deepen its valley even when the surrounding landscape appears unchanged.

The amount of material a river carries depends on the speed and volume of its flow. For example, heavy rainfall may increase both the current and the supply of loose sediment. Scientists measured these changes over many years to understand how seasonal floods reshape river banks.

Deposition occurs when the water slows and can no longer carry the same sediment load. This principle explains the formation of sand bars and broad floodplains near the lower river. Learning to distinguish erosion from deposition helps communities understand why building close to a river can involve long term risks."""},
        {"id": "heldout-business", "article": True, "text": """Regional firms face rising energy costs

Businesses across the region reported lower profits as energy prices increased during the winter. Investors had expected revenue growth to offset those costs, but several companies announced weaker earnings than analysts predicted. Market observers said the pressure was strongest among manufacturers using older equipment.

The financial results do not suggest that every company experienced the same decline. Firms that invested in efficient machinery reported more stable operating costs and stronger sales abroad. However, smaller businesses said they could not easily obtain loans to pay for similar upgrades.

Economists believe the outlook depends partly on whether inflation continues to fall over the coming months. A recovery in consumer spending could improve revenue without immediately restoring profit margins. Analysts cautioned investors against treating one quarter of earnings as proof of a lasting market recovery."""},
        {"id": "heldout-feature", "article": True, "text": """A neighborhood rebuilds its shared garden

For years, families in the neighborhood gathered beside an abandoned railway to grow vegetables together. The community garden became a place where older residents taught children how to care for seedlings and save seeds. Its history reflects the changing lives of families who moved to the area seeking work.

During an interview, residents recalled how a severe storm destroyed the first wooden beds and flooded the storage shed. They did not have enough money to replace everything immediately, so volunteers began collecting materials from nearby businesses. The community organized weekend repairs while families continued growing food in containers at home.

The garden reopened after months of shared work and now includes a sheltered space for neighborhood meetings. Residents said the journey taught them that rebuilding a public place requires patience as well as funding. Younger families have begun keeping a record of the garden's history so that future volunteers understand why it matters."""},
    ]
    return rows
