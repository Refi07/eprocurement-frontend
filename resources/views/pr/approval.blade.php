@extends('layouts.app')

@section('title', 'Persetujuan Purchase Requisition')

@section('content')
<section class="section">
    <div class="section-header">
        <h1>Persetujuan Purchase Requisition (PR)</h1>
    </div>

    <div class="section-body">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible show fade">
                <div class="alert-body">
                    <button class="close" data-dismiss="alert"><span>&times;</span></button>
                    {{ session('success') }}
                </div>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible show fade">
                <div class="alert-body">
                    <button class="close" data-dismiss="alert"><span>&times;</span></button>
                    <ul class="mb-0 pl-3">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <h4>Daftar PR Menunggu Persetujuan Level {{ $level }}</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-md">
                        <thead>
                            <tr>
                                <th>No. PR</th>
                                <th>Pemohon / Dept</th>
                                <th>Deskripsi Kebutuhan</th>
                                <th>Total Nominal</th>
                                <th>Tingkat Approval</th>
                                <th>Status</th>
                                <th style="min-width: 260px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($prs as $pr)
                                <tr>
                                    <td><strong>{{ $pr->number }}</strong></td>
                                    <td>
                                        {{ $pr->user->name ?? '-' }}
                                        <br><small class="text-muted">{{ $pr->department ?? '-' }}</small>
                                    </td>
                                    <td>
                                        {{ $pr->description }}
                                        <br><small class="text-muted">{{ $pr->quantity }} {{ $pr->unit }}</small>
                                    </td>
                                    <td>Rp {{ number_format($pr->estimated_total, 0, ',', '.') }}</td>
                                    <td>
                                        @if($pr->approval_level == 2)
                                            <span class="badge badge-info">Level 2 (&gt;50JT)</span>
                                        @else
                                            <span class="badge badge-primary">Level 1 (&lt;=50JT)</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge badge-warning">
                                            Pending Approval {{ $pr->status === 'pending_l2' ? 'L2' : 'L1' }}
                                        </span>
                                    </td>
                                    <td>
                                        <form action="{{ route('pr.decision', $pr->id) }}" method="POST">
                                            @csrf
                                            <input type="text" name="reason" class="form-control form-control-sm mb-2"
                                                   placeholder="Alasan (isi jika ditolak)">
                                            <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm"
                                                    onclick="return confirm('Setujui PR {{ $pr->number }}?')">
                                                <i class="fas fa-check"></i> Setujui
                                            </button>
                                            <button type="submit" name="decision" value="reject" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Tolak PR {{ $pr->number }}?')">
                                                <i class="fas fa-times"></i> Tolak
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">Tidak ada PR yang menunggu persetujuan level ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection