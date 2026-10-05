@extends('layouts.app')

@section('title', 'Comment ça marche')

@section('content')
    <x-page-header title="Comment ça marche" lead="Foodtruck t'évite de te creuser la tête : tu choisis des plats, il prépare la liste de courses et surveille ton budget." />

    <section class="panel">
        <h2>La semaine en 4 étapes</h2>
        <ol class="howto">
            <li><div><strong>Choisis les repas</strong><span>Dans « Menus », ajoute des plats aux jours qui t'intéressent. Les quantités s'adaptent à ton foyer.</span></div></li>
            <li><div><strong>Prépare la liste</strong><span>Dans « Courses », une liste se calcule toute seule, rangée par magasin et par rayon, avec une estimation du prix.</span></div></li>
            <li><div><strong>Fais les courses</strong><span>Coche les articles dans le magasin. Toute la famille voit la liste en direct sur son téléphone.</span></div></li>
            <li><div><strong>Fais le bilan</strong><span>Rattache ton ticket de caisse : tu vois ce que tu as payé, et les prix qui ont bougé.</span></div></li>
        </ol>
        <a class="btn" href="{{ route('home') }}">Retour à l'accueil</a>
    </section>

    <section class="panel faq">
        <h2>Questions fréquentes</h2>
        <details><summary>Comment ajouter ma propre recette ?</summary><p>Dans « Recettes », bouton « Nouvelle recette ». Tu peux saisir les ingrédients et chaque étape. Elle sera visible par tous les foyers, modifiable par toi.</p></details>
        <details><summary>Comment faire des économies ?</summary><p>Fixe ton budget dans « Mon foyer ». Le planning t'avertit quand il est entamé et propose des plats moins chers. Les restes et le stock sont déduits de la liste.</p></details>
        <details><summary>Que font les coefficients des personnes ?</summary><p>Un enfant mange moins qu'un adulte : Foodtruck compte des « parts » selon l'âge (par exemple 0,5 à 4 ans). Renseigne les dates de naissance dans « Mon foyer ».</p></details>
        <details><summary>À quoi servent les tickets de caisse ?</summary><p>Ils donnent les vrais prix payés, magasin par magasin. Foodtruck s'en sert pour estimer les prochaines listes plus juste.</p></details>
        <details><summary>Comment inviter quelqu'un de la famille ?</summary><p>Dans « Mon foyer », section « Comptes », crée un lien d'invitation valable 7 jours et envoie-le.</p></details>
    </section>
@endsection
