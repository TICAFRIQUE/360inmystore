@extends('layouts.app')

@section('title', "Formules d'abonnement")

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="h4 mb-0">Formules d'abonnement</h2>
            <div class="text-secondary small">
                Ce sont les tarifs affichés sur le site et proposés à chaque entreprise. Une formule à 0 F est
                l'essai gratuit, proposé une seule fois au démarrage d'un espace.
            </div>
        </div>
        <a href="{{ route('accueil') }}#tarifs" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right me-1"></i>Voir la page des tarifs
        </a>
    </div>

    {{-- edition : formule ouverte dans le formulaire (null = nouvelle formule).
         Un seul formulaire pour créer et modifier : son adresse et sa méthode
         suivent la formule choisie. --}}
    <div class="row g-3"
         x-data="{
            routes: { creer: '{{ route('plateforme.formules.store') }}', modifier: '{{ route('plateforme.formules.update', ['formule' => '__ID__']) }}' },
            vide() { return { id: null, nom: '', jours: '', illimite: false, prix: '', description: '', avantages: '', mise_en_avant: false }; },
            edition: { id: null, nom: '', jours: '', illimite: false, prix: '', description: '', avantages: '', mise_en_avant: false },
            ouvrir(formule) { this.edition = { ...formule, jours: formule.jours ?? '', description: formule.description ?? '', avantages: formule.avantages ?? '' }; $refs.nom.focus(); },
            reinitialiser() { this.edition = this.vide(); },
         }">
        <div class="col-lg-7">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Formule</th>
                                <th>Durée</th>
                                <th class="text-end">Prix</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($formules as $formule)
                                <tr>
                                    <td>
                                        <div class="fw-medium">
                                            {{ $formule->nom }}
                                            @if ($formule->mise_en_avant) <span class="badge text-bg-primary ms-1">Mise en avant</span> @endif
                                            @if ($formule->estGratuite()) <span class="badge text-bg-info ms-1">Essai</span> @endif
                                        </div>
                                        @if ($formule->description)
                                            <div class="small text-secondary">{{ $formule->description }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $formule->illimite ? 'Illimitée' : $formule->jours.' jours' }}</td>
                                    <td class="text-end">{{ montant($formule->prix) }}</td>
                                    <td>
                                        @if ($formule->actif)
                                            <span class="badge text-bg-success">Proposée</span>
                                        @else
                                            <span class="badge text-bg-secondary">Retirée</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-secondary" title="Modifier"
                                                @click="ouvrir({{ Js::from($formule->only(['id', 'nom', 'jours', 'illimite', 'prix', 'description', 'avantages', 'mise_en_avant'])) }})">
                                            <i class="bi bi-pencil"></i>
                                            <span class="visually-hidden">Modifier</span>
                                        </button>
                                        <form method="POST" action="{{ route('plateforme.formules.basculer', $formule) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                {{ $formule->actif ? 'Retirer' : 'Proposer' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-secondary py-4">
                                        Aucune formule : tant qu'il n'y en a pas, une entreprise inscrite ne peut pas accéder à son espace.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header" x-text="edition?.id ? 'Modifier la formule' : 'Nouvelle formule'">Nouvelle formule</div>
                <div class="card-body">
                    <form method="POST" :action="edition?.id ? routes.modifier.replace('__ID__', edition.id) : routes.creer">
                        @csrf
                        <template x-if="edition?.id">
                            <input type="hidden" name="_method" value="PUT">
                        </template>

                        <div class="mb-3">
                            <label for="nom" class="form-label">Nom<span class="required-marker">*</span></label>
                            <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror" maxlength="255" required
                                   x-ref="nom" x-model="edition.nom">
                            @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label for="prix" class="form-label">Prix ({{ App\Models\Devise::abreviationActuelle() }})<span class="required-marker">*</span></label>
                                <input type="number" name="prix" id="prix" class="form-control @error('prix') is-invalid @enderror" min="0" required x-model="edition.prix">
                                @error('prix') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">0 = essai gratuit.</div>
                            </div>
                            <div class="col-sm-6">
                                <label for="jours" class="form-label">Durée (jours)</label>
                                <input type="number" name="jours" id="jours" class="form-control @error('jours') is-invalid @enderror" min="1"
                                       x-model="edition.jours" :disabled="edition.illimite" :required="!edition.illimite">
                                @error('jours') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-check mt-1">
                                    <input type="checkbox" name="illimite" value="1" class="form-check-input" id="illimite" x-model="edition.illimite">
                                    <label class="form-check-label" for="illimite">Sans limite de durée</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Phrase d'accroche</label>
                            <input type="text" name="description" id="description" class="form-control" maxlength="255" x-model="edition.description"
                                   placeholder="Ex. Pour un commerce avec un seul magasin">
                        </div>
                        <div class="mb-3">
                            <label for="avantages" class="form-label">Ce que la formule inclut</label>
                            <textarea name="avantages" id="avantages" class="form-control" rows="4" maxlength="2000" x-model="edition.avantages"
                                      placeholder="Un élément par ligne"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" name="mise_en_avant" value="1" class="form-check-input" id="mise_en_avant" x-model="edition.mise_en_avant">
                            <label class="form-check-label" for="mise_en_avant">Mettre en avant sur la page des tarifs</label>
                        </div>

                        <button type="submit" class="btn btn-primary" x-text="edition?.id ? 'Enregistrer' : 'Créer la formule'">Créer la formule</button>
                        <button type="button" class="btn btn-link" x-show="edition?.id" x-cloak @click="reinitialiser()">Annuler</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Contact affiché aux entreprises</div>
                <div class="card-body">
                    <p class="text-secondary small">Affiché dans l'espace d'une entreprise dont l'abonnement a expiré.</p>
                    <form method="POST" action="{{ route('plateforme.contact.update') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="contact-telephone" class="form-label">Téléphone</label>
                            <x-champ-telephone :valeur="old('telephone', $contact->telephone)" id="contact-telephone" />
                        </div>
                        <div class="mb-3">
                            <label for="whatsapp" class="form-label">WhatsApp</label>
                            <x-champ-telephone nom="whatsapp" :valeur="old('whatsapp', $contact->whatsapp)" />
                        </div>
                        <div class="mb-3">
                            <label for="message" class="form-label">Message (optionnel)</label>
                            <textarea name="message" id="message" class="form-control" rows="2" maxlength="1000">{{ old('message', $contact->message) }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Enregistrer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
