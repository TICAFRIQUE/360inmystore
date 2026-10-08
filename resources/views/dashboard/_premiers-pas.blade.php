{{-- Mise en route (voir App\Support\PremiersPas) : affichée tant qu'il reste
     une étape. Seule la première étape à faire porte un bouton — les
     suivantes en dépendent (pas de caisse sans magasin, pas de vente sans
     stock) et restent en attente. --}}
@if ($premiersPas)
    @php
        $faites = collect($premiersPas)->where('fait', true)->count();
        $prochaine = collect($premiersPas)->search(fn (array $etape) => ! $etape['fait']);
    @endphp

    <div class="card border-0 shadow-sm mb-3 premiers-pas">
        <div class="card-body">
            <div class="carte-titre">
                <div>
                    <h3 class="carte-titre__texte"><i class="bi bi-rocket-takeoff text-primary me-2"></i>Mise en route</h3>
                    <p class="carte-titre__aide">Quelques étapes pour commencer à vendre. Ce guide disparaît une fois la première vente faite.</p>
                </div>
                <div class="premiers-pas__avancement">
                    <span>{{ $faites }} sur {{ count($premiersPas) }}</span>
                    <div class="progress" role="progressbar" aria-label="Avancement de la mise en route"
                         aria-valuenow="{{ $faites }}" aria-valuemin="0" aria-valuemax="{{ count($premiersPas) }}">
                        <div class="progress-bar" style="width: {{ round($faites / count($premiersPas) * 100) }}%"></div>
                    </div>
                </div>
            </div>

            <ol class="premiers-pas__etapes">
                @foreach ($premiersPas as $i => $etape)
                    <li @class(['premiers-pas__etape', 'est-faite' => $etape['fait'], 'est-prochaine' => $i === $prochaine])>
                        <span class="premiers-pas__pastille">
                            @if ($etape['fait'])
                                <i class="bi bi-check-lg"></i><span class="visually-hidden">Fait :</span>
                            @else
                                {{ $i + 1 }}
                            @endif
                        </span>
                        <div class="premiers-pas__texte">
                            <strong>{{ $etape['titre'] }}</strong>
                            <span>{{ $etape['texte'] }}</span>
                        </div>
                        @if ($i === $prochaine)
                            @if ($etape['action'])
                                <a href="{{ $etape['action']['url'] }}" class="btn btn-primary flex-shrink-0">
                                    {{ $etape['action']['libelle'] }}<i class="bi bi-arrow-right ms-2"></i>
                                </a>
                            @else
                                <span class="premiers-pas__attente">À faire par votre responsable</span>
                            @endif
                        @elseif ($etape['fait'])
                            <span class="premiers-pas__attente text-success"><i class="bi bi-check2 me-1"></i>Fait</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
@endif
