{{-- Cartes des formules d'abonnement (catalogue de la plateforme), partagées
     par le site vitrine et par le choix de formule dans l'espace d'une
     entreprise. Le bouton de chaque carte est fourni par l'appelant
     ($action : nom d'une vue partielle recevant $formule), puisqu'il n'a pas
     le même rôle aux deux endroits. $actuelle : formule en cours de
     l'entreprise (sa copie locale — même nom et même prix, pas le même
     identifiant que celle du catalogue). --}}
@props(['formules', 'action', 'actuelle' => null])

<div class="row g-3 justify-content-center">
    @foreach ($formules as $formule)
        @php $estActuelle = $actuelle !== null && $actuelle->nom === $formule->nom && $actuelle->prix === $formule->prix; @endphp
        <div class="col-sm-6 col-lg-4">
            <div class="formule-pricing-card @if ($formule->mise_en_avant || $estActuelle) formule-pricing-card--actuelle @endif">
                @if ($estActuelle)
                    <span class="badge text-bg-primary formule-pricing-card__badge">Votre formule actuelle</span>
                @elseif ($formule->mise_en_avant)
                    <span class="badge text-bg-primary formule-pricing-card__badge">Recommandée</span>
                @endif

                <div class="formule-pricing-card__nom">{{ $formule->nom }}</div>
                <div class="formule-pricing-card__prix">{{ $formule->estGratuite() ? 'Gratuit' : montant($formule->prix) }}</div>
                <div class="formule-pricing-card__duree">
                    <i class="bi bi-clock-history me-1"></i>{{ $formule->illimite ? 'Sans limite de durée' : $formule->jours.' jours' }}
                </div>

                @if ($formule->description)
                    <p class="text-secondary small mt-3 mb-0">{{ $formule->description }}</p>
                @endif

                {{-- Les limites réglées par l'éditeur s'affichent d'elles-mêmes,
                     avant ce qu'il a rédigé : le tarif dit toujours ce qui est permis. --}}
                @if ($formule->listeAvantages() || $formule->limitesLisibles())
                    <ul class="formule-pricing-card__avantages">
                        @foreach ($formule->limitesLisibles() as $limite)
                            <li><i class="bi bi-check2"></i><span>Jusqu'à {{ $limite }}</span></li>
                        @endforeach
                        @foreach ($formule->listeAvantages() as $avantage)
                            <li><i class="bi bi-check2"></i><span>{{ $avantage }}</span></li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-auto pt-3">
                    @include($action, ['formule' => $formule])
                </div>
            </div>
        </div>
    @endforeach
</div>
