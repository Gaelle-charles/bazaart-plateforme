---
name: feedback-no-em-dashes
description: Règle éditoriale ferme : interdiction des tirets cadratins (—) et demi-cadratins (–) dans tous les contenus du site
metadata:
  type: feedback
---

Gaëlle interdit l'usage du tiret cadratin « — » (U+2014) et du tiret demi-cadratin « – » (U+2013) dans TOUS les titres et contenus du site Bazaart.

**Why:** Ces caractères proviennent systématiquement du LLM malgré les consignes, et ne correspondent pas à la charte typographique du site.

**How to apply:**
- Dans TOUS les prompts LLM (titre ET description, Mistral ET Anthropic) : ajouter une consigne TYPOGRAPHIE OBLIGATOIRE explicite interdisant — et –.
- Ne pas utiliser — ou – dans les EXEMPLES du prompt non plus (le LLM les imite).
- Pour les exemples de titres dans les prompts : utiliser ":" ou "()" à la place de "—".
- Implémenter une méthode `stripEmDashes(string $text): string` dans chaque service LLM comme filet PHP post-parsing : remplace " — " et " – " par " : ", les cadratins résiduels par "-", puis collapse les espaces multiples.
- Appliquer ce filet sur TOUS les champs texte produits par le LLM : title, description, howToApply, fundingAmount, fundingType (avant tronquage).
- Les données historiques en BDD peuvent rester (correction lors du prochain enrichissement batch) ; seul le pipeline futur est garanti.
- Voir [[project_ressourcerie_adr0016_lot1]] pour le contexte général de l'enrichissement LLM.
