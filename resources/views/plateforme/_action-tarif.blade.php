{{-- Bouton d'une carte de formule sur le site vitrine (voir x-tarifs) : le
     choix réel se fait après l'inscription, dans l'espace du client. --}}
@if (config('plateforme.inscription.ouverte'))
    <a href="{{ route('plateforme.inscription') }}" class="btn {{ $formule->mise_en_avant ? 'btn-primary' : 'btn-outline-primary' }} w-100">
        {{ $formule->estGratuite() ? 'Essayer gratuitement' : 'Commencer' }}
    </a>
@endif
