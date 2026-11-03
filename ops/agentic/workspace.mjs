import { mkdtemp, mkdir, symlink, lstat, readlink, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

// Trusted CLI bookkeeping lives only in bounded scratch, outside its empty work
// directory. The optional credential reference always points at the caller's
// fixed read-only auth mount; no credential contents are copied into scratch.
export async function createDecisionWorkspace(credentialFile = null) {
  const directory = await mkdtemp(join(tmpdir(), 'herokid-decision-'));
  const home = join(directory, 'home'), work = join(directory, 'work');
  try {
    await mkdir(home, {mode: 0o700}); await mkdir(work, {mode: 0o700});
    if (credentialFile) await symlink(credentialFile, join(home, 'auth.json'));
    return {directory, home, work, credentialFile};
  } catch (error) {
    await rm(directory, {recursive: true, force: true});
    throw error;
  }
}

export async function credentialReferenceIntact(workspace) {
  if (! workspace.credentialFile) return true;
  try {
    const path = join(workspace.home, 'auth.json');
    return (await lstat(path)).isSymbolicLink() && await readlink(path) === workspace.credentialFile;
  } catch { return false; }
}

export async function removeDecisionWorkspace(workspace) {
  if (workspace) await rm(workspace.directory, {recursive: true, force: true});
}
